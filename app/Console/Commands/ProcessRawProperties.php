<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Models\RawRentProperty;
use App\Models\RawSaleProperty;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ProcessRawProperties extends Command
{
    protected $signature = 'properties:process-raw
                            {--city= : Limita el proceso a una ciudad concreta}
                            {--fresh : Elimina todas las propiedades procesadas antes de reprocesar}
                            {--strict-real-rent : Solo usa monthly_rent real en ventas (sin fallback de comparables)}';

    protected $description = 'Procesa las tablas raw de venta y alquiler y puebla la tabla properties con cálculos reales';

    // Umbrales para construir comparables de alquiler al estimar monthly_rent en inmuebles de venta.
    private const RENT_COMP_RADIUS_KM = 1.5; // Radio geografico para buscar comparables cerca del inmueble objetivo.
    private const RENT_PER_M2_MIN     = 4.0; // Filtro inferior de alquiler por m2 para descartar outliers no realistas.
    private const RENT_PER_M2_MAX     = 80.0; // Filtro superior de alquiler por m2 para descartar anuncios atipicos.
    private const RENT_COMP_MIN       = 3; // Minimo de comparables para que la mediana tenga robustez estadistica.
    private const AREA_MIN            = 20; // Area minima valida para comparables (evita estudios/anuncios con ruido extremo).
    private const AREA_MAX            = 500; // Area maxima valida para comparables (evita activos singulares no comparables).

    // Ponderaciones y objetivo del investment_score (escala final 0-100).
    private const SCORE_YIELD_WEIGHT  = 0.70; // Peso de la rentabilidad en el score final.
    private const SCORE_YIELD_TARGET  = 7.0; // Yield bruto (%) considerado excelente para normalizar la componente yield.
    private const SCORE_PRICE_WEIGHT  = 0.30; // Peso del precio relativo por m2 frente al mercado local.

    // Limites/modelo financiero para evitar metricas irreales por outliers de datos.
    private const YIELD_GROSS_MAX = 12.0; // Tope de yield bruto anual permitido en el dataset.
    private const NET_INCOME_FACTOR = 0.75; // Ingreso neto anual estimado: 75% de la renta bruta anual.
    private const ROI_ACQUISITION_COST_FACTOR = 1.10; // Inversion total = precio compra + 10% costes de adquisicion.

    /**
     * Resumen funcional de calculos principales:
     * - monthly_rent (venta): mediana de comparables de alquiler por m2 * area_m2.
     * - yield_gross: ((monthly_rent * 12) / price) * 100.
     * - yield_net: yield_gross * 0.75.
    * - roi_annual: ((monthly_rent * 12 * 0.75) / (price * 1.10)) * 100.
     * - investment_score: 70% componente yield + 30% componente precio/m2 vs mediana local.
     */

    public function handle(): int
    {
        $city = $this->option('city');
        $strictRealRent = (bool) $this->option('strict-real-rent');

        if ($this->option('fresh')) {
            // En modo fresh se rehace el dataset procesado completo (global o por ciudad).
            $deleted = $city
                ? Property::query()->where('city', $city)->delete()
                : Property::query()->delete();
            $this->info("Propiedades previas eliminadas: {$deleted}");
        }

        // ── 1. Procesar alquileres ──────────────────────────────────────────
        $this->info('Procesando alquileres raw...');
        $rentQuery = RawRentProperty::query()->whereNotNull('price_value')->where('price_value', '>', 0);
        if ($city) {
            $rentQuery->where('city', $city);
        }

        $rentProcessed = 0;
        $rentQuery->chunkById(200, function (Collection $chunk) use (&$rentProcessed): void {
            foreach ($chunk as $raw) {
                $this->upsertRentProperty($raw);
                $rentProcessed++;
            }
        });
        $this->info("  Alquileres procesados: {$rentProcessed}");

        // ── 2. Cargar comparables de alquiler en memoria (si aplica fallback) ──
        $rentComps = collect();
        if (!$strictRealRent) {
            $this->info('Cargando comparables de alquiler...');
            $rentComps = $this->loadRentComps($city);
            $this->info("  Comparables cargados: {$rentComps->count()}");
        } else {
            $this->warn('Modo estricto activo: no se usaran comparables para ventas sin renta real.');
        }

        // ── 3. Procesar ventas ──────────────────────────────────────────────
        $this->info('Procesando ventas raw...');
        $saleQuery = RawSaleProperty::query()->whereNotNull('price_value')->where('price_value', '>', 0);
        if ($city) {
            $saleQuery->where('city', $city);
        }

        $saleProcessed = 0;
        $noBasis       = 0;
        $saleQuery->chunkById(200, function (Collection $chunk) use ($rentComps, $strictRealRent, &$saleProcessed, &$noBasis): void {
            foreach ($chunk as $raw) {
                $hasBasis = $this->upsertSaleProperty($raw, $rentComps, $strictRealRent);
                $saleProcessed++;
                if (!$hasBasis) {
                    $noBasis++;
                }
            }
        });

        $this->info("  Ventas procesadas: {$saleProcessed}");
        if ($noBasis > 0) {
            if ($strictRealRent) {
                $this->warn("  Sin renta mensual real en raw_sale_properties: {$noBasis} (monthly_rent=null, score=0)");
            } else {
                $this->warn("  Sin base de renta (real o comparables suficientes): {$noBasis} (monthly_rent=null, score=0)");
            }
        }

        $total = Property::query()->when($city, fn ($q) => $q->where('city', $city))->count();
        $this->info("✓ properties total tras proceso: {$total}");

        return self::SUCCESS;
    }

    // ── Alquiler ─────────────────────────────────────────────────────────────

    private function upsertRentProperty(RawRentProperty $raw): void
    {
        $source      = $raw->source ?? 'fotocasa';
        $rawExternalId = (string) $raw->external_id;
        // ID canonico de negocio para evitar colisiones entre venta/alquiler y entre fuentes.
        $externalId  = $this->buildPropertyExternalId($source, 'rent', $rawExternalId);
        $legacySourceExternalId = str_starts_with($rawExternalId, $source . '-') ? $rawExternalId : ($source . '-' . $rawExternalId);
        $this->migrateLegacyExternalId($externalId, $rawExternalId, 'rent');
        $this->migrateLegacyExternalId($externalId, $legacySourceExternalId, 'rent');
        $monthlyRent = (int) $raw->price_value;
        $areaM2      = (int) ($raw->area_m2 ?: 0);

        Property::query()->updateOrCreate(
            ['external_id' => $externalId],
            [
                // Identidad y clasificacion del anuncio.
                'source'           => $source,
                'title'            => $raw->title ?? '',
                'description'      => $raw->description,
                'property_type'    => $raw->property_type ?? 'activo',
                'listing_mode'     => 'rent',

                // Geolocalizacion para analitica espacial y comparables.
                'lat'              => (float) $raw->lat,
                'lng'              => (float) $raw->lng,

                // Pricing: en alquiler el precio de venta se fuerza a 0 y se usa monthly_rent.
                'price'            => 0,
                'monthly_rent'     => $monthlyRent,

                // Atributos fisicos para filtros y modelos de scoring.
                'bedrooms'         => (int) ($raw->bedrooms ?: 0),
                'bathrooms'        => (int) ($raw->bathrooms ?: 0),
                'area_m2'          => $areaM2,
                'city'             => $raw->city ?? '',
                'region_slug'      => $raw->region_slug ?? '',

                // Metricas de inversion: en alquiler puro no aplican yields del pipeline de venta.
                'yield_gross'      => 0.0,
                'yield_net'        => 0.0,
                'roi_annual'       => 0.0,
                'investment_score' => 0,

                // Trazabilidad de origen y payload para auditoria/debug.
                'detail_url'       => $raw->detail_url,
                'source_url'       => $raw->source_url,
                'images'           => $raw->downloaded_images ?: $raw->images,
                'source_payload'   => $raw->source_payload,
                'scraped_at'       => $raw->scraped_at,
            ]
        );
    }

    // ── Venta ─────────────────────────────────────────────────────────────────

    private function upsertSaleProperty(RawSaleProperty $raw, Collection $rentComps, bool $strictRealRent): bool
    {
        $source = $raw->source ?? 'fotocasa';
        $rawExternalId = (string) $raw->external_id;
        // ID canonico de negocio para evitar colisiones entre venta/alquiler y entre fuentes.
        $externalId = $this->buildPropertyExternalId($source, 'sale', $rawExternalId);
        $legacySourceExternalId = str_starts_with($rawExternalId, $source . '-') ? $rawExternalId : ($source . '-' . $rawExternalId);
        $this->migrateLegacyExternalId($externalId, $rawExternalId, 'sale');
        $this->migrateLegacyExternalId($externalId, $legacySourceExternalId, 'sale');
        $price  = (int) $raw->price_value;
        $areaM2 = (int) ($raw->area_m2 ?: 0);
        $lat    = (float) $raw->lat;
        $lng    = (float) $raw->lng;

        [$monthlyRent, $hasBasis] = $this->resolveMonthlyRentForSale($raw, $price, $rentComps, $strictRealRent);

        [$yieldGross, $yieldNet, $roiAnnual, $score] = $this->computeMetrics($price, $monthlyRent, $areaM2, $lat, $lng, $raw->city, $rentComps);

        Property::query()->updateOrCreate(
            ['external_id' => $externalId],
            [
                // Identidad y clasificacion del anuncio.
                'source'           => $source,
                'title'            => $raw->title ?? '',
                'description'      => $raw->description,
                'property_type'    => $raw->property_type ?? 'activo',
                'listing_mode'     => 'sale',

                // Geolocalizacion para analitica espacial y comparables.
                'lat'              => $lat,
                'lng'              => $lng,

                // Pricing: en venta se guarda price y se estima monthly_rent para calcular yields.
                'price'            => $price,
                'monthly_rent'     => $monthlyRent,

                // Atributos fisicos para filtros y modelos de scoring.
                'bedrooms'         => (int) ($raw->bedrooms ?: 0),
                'bathrooms'        => (int) ($raw->bathrooms ?: 0),
                'area_m2'          => $areaM2,
                'city'             => $raw->city ?? '',
                'region_slug'      => $raw->region_slug ?? '',

                // Metricas de inversion calculadas en computeMetrics/computeScore.
                'yield_gross'      => $yieldGross,
                'yield_net'        => $yieldNet,
                'roi_annual'       => $roiAnnual,
                'investment_score' => $score,

                // Trazabilidad de origen y payload para auditoria/debug.
                'detail_url'       => $raw->detail_url,
                'source_url'       => $raw->source_url,
                'images'           => $raw->downloaded_images ?: $raw->images,
                'source_payload'   => $raw->source_payload,
                'scraped_at'       => $raw->scraped_at,
            ]
        );

        return $hasBasis;
    }

    /**
     * Resuelve monthly_rent para ventas con prioridad en dato real.
     *
     * Orden:
     * 1) monthly_rent real en raw_sale_properties.
     * 2) (si no hay real y no es modo estricto) estimacion con comparables reales de alquiler.
     *
     * Devuelve [?int $monthlyRent, bool $hasBasis].
     */
    private function resolveMonthlyRentForSale(RawSaleProperty $raw, int $price, Collection $rentComps, bool $strictRealRent): array
    {
        $rawMonthlyRent = is_numeric($raw->monthly_rent) ? (int) $raw->monthly_rent : null;

        $monthlyRent = null;
        $hasBasis = false;

        if ($rawMonthlyRent !== null && $rawMonthlyRent > 0) {
            $monthlyRent = $rawMonthlyRent;
            $hasBasis = true;
        } elseif (!$strictRealRent) {
            [$estimatedMonthlyRent, $hadComp] = $this->estimateMonthlyRent($raw, $rentComps);
            if ($hadComp && $estimatedMonthlyRent !== null && $estimatedMonthlyRent > 0) {
                $monthlyRent = $estimatedMonthlyRent;
                $hasBasis = true;
            }
        }

        if ($monthlyRent !== null && $price > 0) {
            // Guardrail defensivo: no permitir incoherencia extrema contra el precio.
            $maxMonthlyRentByYieldCap = (int) floor((($price * self::YIELD_GROSS_MAX) / 100) / 12);
            $maxMonthlyRentByYieldCap = max(1, $maxMonthlyRentByYieldCap);
            $monthlyRent = min($monthlyRent, $maxMonthlyRentByYieldCap);
        }

        return [$monthlyRent, $hasBasis];
    }

    // ── Comparables ───────────────────────────────────────────────────────────

    /**
     * Carga todos los comparables de alquiler válidos en memoria para evitar N+1 queries.
     * Cada elemento: [lat, lng, city, property_type, rent_per_m2]
     */
    private function loadRentComps(?string $city): Collection
    {
        $query = RawRentProperty::query()
            ->whereNotNull('price_value')
            ->where('price_value', '>', 0)
            ->whereNotNull('area_m2')
            ->whereBetween('area_m2', [self::AREA_MIN, self::AREA_MAX])
            ->whereNotNull('lat')
            ->whereNotNull('lng');

        if ($city) {
            $query->where('city', $city);
        }

        return $query
            ->select(['lat', 'lng', 'city', 'property_type', 'price_value', 'area_m2'])
            ->get()
            ->map(function ($row): ?array {
                $areaM2    = (float) $row->area_m2;
                $priceVal  = (float) $row->price_value;
                if ($areaM2 <= 0) {
                    return null;
                }
                $perM2 = $priceVal / $areaM2;
                if ($perM2 < self::RENT_PER_M2_MIN || $perM2 > self::RENT_PER_M2_MAX) {
                    return null;
                }

                return [
                    'lat'           => (float) $row->lat,
                    'lng'           => (float) $row->lng,
                    'city'          => $row->city,
                    'property_type' => $row->property_type,
                    'rent_per_m2'   => $perM2,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Estima el alquiler mensual de un inmueble en venta usando comparables cercanos.
     * Devuelve [?int $monthlyRent, bool $hadComparables].
      *
      * Operacion:
      * 1) rent_per_m2 = price_value / area_m2 para comparables validos.
      * 2) monthly_rent_estimado = mediana(rent_per_m2_cercanos) * area_m2_venta.
      * 3) Se aplica cap por ciudad para evitar outliers extremos.
      *
      * Retorna null cuando no hay comparables suficientes (o faltan datos base),
      * lo que provoca yield_gross=0 en computeMetrics.
     */
    private function estimateMonthlyRent(RawSaleProperty $raw, Collection $rentComps): array
    {
        $lat    = (float) $raw->lat;
        $lng    = (float) $raw->lng;
        $areaM2 = (int) ($raw->area_m2 ?: 0);

        if ($areaM2 <= 0 || $lat == 0 || $lng == 0) {
            return [null, false];
        }

        $nearby = $this->nearbyRentPerM2($rentComps, $lat, $lng, $raw->city, $raw->property_type);

        if ($nearby->count() < self::RENT_COMP_MIN) {
            // Ampliar búsqueda sin filtrar por tipo de inmueble
            $nearby = $this->nearbyRentPerM2($rentComps, $lat, $lng, $raw->city, null);
        }

        if ($nearby->count() < self::RENT_COMP_MIN) {
            return [null, false];
        }

        $median      = $this->median($nearby);
        $monthlyRent = max(1, (int) round($median * $areaM2));

        // Cap al percentil 90 de la ciudad
        $cityMedian = $this->cityMedianRentPerM2($rentComps, $raw->city);
        // 1.5x de la mediana de ciudad como tope pragmatico anti-outlier.
        if ($cityMedian !== null && $monthlyRent > $cityMedian * $areaM2 * 1.5) {
            $monthlyRent = (int) round($cityMedian * $areaM2 * 1.5);
        }

        return [$monthlyRent, true];
    }

    private function nearbyRentPerM2(Collection $comps, float $lat, float $lng, ?string $city, ?string $propertyType): Collection
    {
        $radiusKm  = self::RENT_COMP_RADIUS_KM;
        $latDelta  = $radiusKm / 111.32;
        $cosLat    = cos(deg2rad($lat));
        // Evita divisiones extremas cerca de polos manteniendo un coseno minimo estable.
        $safeCos   = abs($cosLat) < 0.2 ? 0.2 : abs($cosLat);
        $lngDelta  = $radiusKm / (111.32 * $safeCos);

        return $comps->filter(function (array $comp) use ($lat, $lng, $latDelta, $lngDelta, $city, $propertyType): bool {
            if ($city && $comp['city'] !== $city) {
                return false;
            }
            if ($propertyType && $comp['property_type'] !== $propertyType) {
                return false;
            }
            if (abs($comp['lat'] - $lat) > $latDelta) {
                return false;
            }
            if (abs($comp['lng'] - $lng) > $lngDelta) {
                return false;
            }

            return true;
        })->pluck('rent_per_m2');
    }

    private function cityMedianRentPerM2(Collection $comps, ?string $city): ?float
    {
        $values = $comps
            ->when($city, fn ($c) => $c->filter(fn (array $r) => $r['city'] === $city))
            ->pluck('rent_per_m2');

        return $values->count() >= 3 ? $this->median($values) : null;
    }

    // ── Métricas ──────────────────────────────────────────────────────────────

    /**
    * @return array{float, float, float, int}  [yield_gross, yield_net, roi_annual, investment_score]
     *
     * Reglas de negocio:
     * - Si price <= 0 o monthly_rent es null/<=0 => yield_gross=0, yield_net=0, roi_annual=0, score=0.
     * - Si hay datos validos:
     *   yield_gross = round(((monthly_rent * 12) / price) * 100, 1)
     *   yield_net = round(yield_gross * 0.75, 1)
     *   roi_annual = round(((monthly_rent*12*0.75)/(price*1.10))*100, 1)
     *
     * Nota: se limita yield_gross con YIELD_GROSS_MAX para evitar valores no realistas por outliers.
     */
    private function computeMetrics(int $price, ?int $monthlyRent, int $areaM2, float $lat, float $lng, ?string $city, Collection $rentComps): array
    {
        // Sin precio de compra o sin renta estimada no existe base para rentabilidad.
        if ($price <= 0 || $monthlyRent === null || $monthlyRent <= 0) {
            return [0.0, 0.0, 0.0, 0];
        }

        // Rentabilidad bruta anual = (renta anual / precio compra) * 100.
        $yieldGross = round(($monthlyRent * 12 / $price) * 100, 1);
        // Techo defensivo ante combinaciones atipicas de renta/precio.
        $yieldGross = min($yieldGross, self::YIELD_GROSS_MAX);

        // Gastos típicos: IBI, comunidad, seguro, vacíos, reparaciones ≈ 25% renta bruta
        $yieldNet = round($yieldGross * self::NET_INCOME_FACTOR, 1);

        // ROI anual neto: retorno neto anual sobre inversion total (precio + costes de adquisicion).
        $roiAnnual = round(($yieldNet / self::ROI_ACQUISITION_COST_FACTOR), 1);

        $score = $this->computeScore($yieldGross, $price, $areaM2, $lat, $lng, $city, $rentComps);

        return [$yieldGross, $yieldNet, $roiAnnual, $score];
    }

    private function computeScore(float $yieldGross, int $price, int $areaM2, float $lat, float $lng, ?string $city, Collection $rentComps): int
    {
        // Componente de rentabilidad (70% del score)
        $yieldScore = min(1.0, $yieldGross / self::SCORE_YIELD_TARGET) * 100 * self::SCORE_YIELD_WEIGHT;

        // Componente de precio por m² vs mercado (30% del score)
        // ratio = (precio_m2_inmueble / mediana_precio_m2_local)
        // ratio < 1 => mas barato que mercado => mejor score.
        $priceScore = 50.0; // neutro por defecto

        if ($areaM2 > 0) {
            $pricePqm   = $price / $areaM2;
            $cityMedian = $this->cityMedianSalePricePerM2($lat, $lng, $city);

            if ($cityMedian !== null && $cityMedian > 0) {
                // Inmueble más barato que la mediana → mejor score
                $ratio      = $pricePqm / $cityMedian;        // <1 barato, >1 caro
                $priceScore = max(0.0, min(100.0, (2 - $ratio) * 50));
            }
        }

        $raw = $yieldScore + ($priceScore * self::SCORE_PRICE_WEIGHT);

        return (int) min(100, max(0, round($raw)));
    }

    private function cityMedianSalePricePerM2(float $lat, float $lng, ?string $city): ?float
    {
        $radiusKm = 3.0; // Radio local para referencia de precio por m2 del entorno.
        $latDelta = $radiusKm / 111.32;
        $cosLat   = cos(deg2rad($lat));
        $safeCos  = abs($cosLat) < 0.2 ? 0.2 : abs($cosLat);
        $lngDelta = $radiusKm / (111.32 * $safeCos);

        $values = RawSaleProperty::query()
            ->when($city, fn ($q) => $q->where('city', $city))
            ->whereNotNull('price_value')
            ->where('price_value', '>', 0)
            ->whereNotNull('area_m2')
            ->whereBetween('area_m2', [self::AREA_MIN, self::AREA_MAX])
            ->whereBetween('lat', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('lng', [$lng - $lngDelta, $lng + $lngDelta])
            ->selectRaw('CAST(price_value AS DECIMAL(14,2)) / area_m2 as price_per_m2')
            ->pluck('price_per_m2')
            ->map(fn ($v) => (float) $v)
            // Banda valida de precio/m2 para eliminar ruido extremo de scraping.
            ->filter(fn (float $v) => $v > 100 && $v < 50000)
            ->sort()
            ->values();

        return $values->count() >= 3 ? $this->median($values) : null;
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    private function buildPropertyExternalId(?string $source, string $listingMode, string $rawExternalId): string
    {
        $sourcePart = trim(strtolower((string) $source));
        if ($sourcePart === '') {
            $sourcePart = 'fotocasa';
        }

        $modePart = trim(strtolower($listingMode));
        $rawPart = trim($rawExternalId);

        if ($rawPart === '') {
            $rawPart = 'unknown';
        }

        $modePrefix = $sourcePart . '-' . $modePart . '-';
        if (str_starts_with($rawPart, $modePrefix)) {
            return $rawPart;
        }

        $sourcePrefix = $sourcePart . '-';
        if (str_starts_with($rawPart, $sourcePrefix)) {
            $rawPart = substr($rawPart, strlen($sourcePrefix));
        }

        return $modePrefix . $rawPart;
    }

    private function migrateLegacyExternalId(string $newExternalId, string $legacyExternalId, string $listingMode): void
    {
        if ($legacyExternalId === '' || $legacyExternalId === $newExternalId) {
            return;
        }

        $alreadyExists = Property::query()->where('external_id', $newExternalId)->exists();
        if ($alreadyExists) {
            return;
        }

        $legacyProperty = Property::query()
            ->where('external_id', $legacyExternalId)
            ->where('listing_mode', $listingMode)
            ->first();

        if (!$legacyProperty) {
            return;
        }

        $legacyProperty->external_id = $newExternalId;
        $legacyProperty->save();
    }

    private function median(Collection $values): float
    {
        $sorted = $values->sort()->values();
        $count  = $sorted->count();

        if ($count === 0) {
            return 0.0;
        }

        $mid = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $sorted[$mid]
            : ((float) $sorted[$mid - 1] + (float) $sorted[$mid]) / 2;
    }
}
