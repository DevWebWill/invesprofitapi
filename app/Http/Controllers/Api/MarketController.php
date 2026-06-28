<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketZoneSnapshot;
use App\Models\Property;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MarketController extends Controller
{
    private const OPPORTUNITY_SCORE_THRESHOLD = 80;
    private const OPPORTUNITY_YIELD_THRESHOLD = 6.0;
    private const MARKET_YIELD_MAX = 12.0;

    private function safeString(?string $value, string $fallback = 'N/D'): string
    {
        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : $fallback;
    }

    /**
     * Clasifica un registro para agregaciones de mercado:
     * - sale: tiene precio de venta util y no tiene renta util.
     * - rent: listing_mode=rent o solo tiene renta util.
     * - both: listing_mode=sale con precio y monthly_rent validos.
     *
     * "both" permite que ventas con renta estimada participen en metricas de renta.
     */
    private function resolveOperation(array $record): string
    {
        $hasSalePrice = is_numeric($record['price']) && (float) $record['price'] > 0;
        $hasMonthlyRent = is_numeric($record['monthly_rent']) && (float) $record['monthly_rent'] > 0;

        if ($hasSalePrice && $hasMonthlyRent && $record['listing_mode'] === 'sale') {
            return 'both';
        }

        if ($record['listing_mode'] === 'rent' || (!$hasSalePrice && $hasMonthlyRent)) {
            return 'rent';
        }

        return 'sale';
    }

    private function hasUsableSalePrice(array $record): bool
    {
        if ($this->resolveOperation($record) === 'rent') {
            return false;
        }

        return is_numeric($record['price']) && (float) $record['price'] > 0;
    }

    private function sanitizeLocation(array $record): array
    {
        return [
            'community' => $this->safeString($record['community']),
            'province' => $this->safeString($record['province']),
            'city' => $this->safeString($record['municipality'] ?: $record['city'] ?: null),
            'district' => $this->safeString($record['district']),
            'neighborhood' => $this->safeString($record['neighborhood']),
        ];
    }

    private function locationIdentity(array $location): array
    {
        $name = $location['neighborhood'] !== 'N/D'
            ? $location['neighborhood']
            : ($location['district'] !== 'N/D' ? $location['district'] : $location['city']);

        $level = $location['neighborhood'] !== 'N/D'
            ? 'neighborhood'
            : ($location['district'] !== 'N/D' ? 'district' : 'city');

        return [
            'name' => $name,
            'level' => $level,
        ];
    }

    private function normalizeRows(): array
    {
        $properties = Property::query()
            ->select([
                'id',
                'external_id',
                'property_type',
                'listing_mode',
                'price',
                'monthly_rent',
                'area_m2',
                'yield_gross',
                'investment_score',
                'region_slug',
                'city',
                'source_payload',
            ])
            ->get();

        return $properties->map(function (Property $property) {
            $payload = is_array($property->source_payload) ? $property->source_payload : [];
            $address = $payload['listing']['address'] ?? [];

            return [
                'id' => $property->id,
                'external_id' => $property->external_id,
                'property_type' => $this->safeString($property->property_type, 'activo'),
                'listing_mode' => $this->safeString($property->listing_mode, 'sale'),
                'price' => $property->price,
                'monthly_rent' => $property->monthly_rent,
                'area_m2' => $property->area_m2,
                'yield_gross' => $property->yield_gross,
                'investment_score' => $property->investment_score,
                'region_slug' => $this->safeString($property->region_slug, 'espana'),
                'city' => $property->city,
                'community' => $address['regionLevel1'] ?? null,
                'province' => $address['province'] ?? null,
                'municipality' => $address['municipality'] ?? null,
                'district' => $address['district'] ?? null,
                'neighborhood' => $address['neighborhood'] ?? ($address['upperLevel'] ?? null),
            ];
        })->all();
    }

    private function applyFilters(array $rows, Request $request): array
    {
        $community = trim((string) $request->input('community', 'all'));
        $province = trim((string) $request->input('province', 'all'));
        $city = trim((string) $request->input('city', 'all'));
        $district = trim((string) $request->input('district', 'all'));
        $neighborhood = trim((string) $request->input('neighborhood', 'all'));
        $assetType = trim((string) $request->input('assetType', 'all'));
        $operation = trim((string) $request->input('operation', 'all'));

        $priceMin = $request->input('priceMin');
        $priceMax = $request->input('priceMax');
        $minYield = $request->input('minYield');
        $minScore = $request->input('minScore');

        return array_values(array_filter($rows, function (array $row) use (
            $community,
            $province,
            $city,
            $district,
            $neighborhood,
            $assetType,
            $operation,
            $priceMin,
            $priceMax,
            $minYield,
            $minScore
        ) {
            $location = $this->sanitizeLocation($row);
            $resolvedOperation = $this->resolveOperation($row);

            if ($community !== 'all' && Str::lower($location['community']) !== Str::lower($community)) {
                return false;
            }

            if ($province !== 'all' && Str::lower($location['province']) !== Str::lower($province)) {
                return false;
            }

            if ($city !== 'all' && Str::lower($location['city']) !== Str::lower($city)) {
                return false;
            }

            if ($district !== 'all' && Str::lower($location['district']) !== Str::lower($district)) {
                return false;
            }

            if ($neighborhood !== 'all' && Str::lower($location['neighborhood']) !== Str::lower($neighborhood)) {
                return false;
            }

            if ($assetType !== 'all' && Str::lower($row['property_type']) !== Str::lower($assetType)) {
                return false;
            }

            if ($operation !== 'all') {
                if ($operation === 'sale' && $resolvedOperation === 'rent') {
                    return false;
                }

                if ($operation === 'rent' && $resolvedOperation === 'sale') {
                    return false;
                }
            }

            $salePricePerSqm = null;
            if ($this->hasUsableSalePrice($row) && is_numeric($row['area_m2']) && (float) $row['area_m2'] > 0) {
                $salePricePerSqm = (float) $row['price'] / (float) $row['area_m2'];
            }

            if ($priceMin !== null && $priceMin !== '' && ($salePricePerSqm === null || $salePricePerSqm < (float) $priceMin)) {
                return false;
            }

            if ($priceMax !== null && $priceMax !== '' && ($salePricePerSqm === null || $salePricePerSqm > (float) $priceMax)) {
                return false;
            }

            if ($minYield !== null && $minYield !== '' && (!is_numeric($row['yield_gross']) || (float) $row['yield_gross'] < (float) $minYield)) {
                return false;
            }

            if ($minScore !== null && $minScore !== '' && (!is_numeric($row['investment_score']) || (float) $row['investment_score'] < (float) $minScore)) {
                return false;
            }

            return true;
        }));
    }

    private function percentageChange(float $current, float $previous): float
    {
        if ($previous <= 0) {
            return 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function withRealHistory(array $zones, Carbon $snapshotMonth): array
    {
        if (!$zones) {
            return $zones;
        }

        $zoneKeys = array_values(array_unique(array_map(fn (array $zone) => $zone['id'], $zones)));
        $fromMonth = $snapshotMonth->copy()->subMonths(11);

        $snapshots = MarketZoneSnapshot::query()
            ->where('data_source', 'real')
            ->where('operation', 'all')
            ->where('asset_type', 'all')
            ->whereIn('zone_key', $zoneKeys)
            ->whereBetween('snapshot_month', [$fromMonth->toDateString(), $snapshotMonth->toDateString()])
            ->orderBy('snapshot_month')
            ->get([
                'zone_key',
                'snapshot_month',
                'avg_sale_price_sqm',
                'avg_rent_price_sqm',
                'avg_monthly_rent',
                'opportunities_count',
            ]);

        $snapshotsByZone = [];
        foreach ($snapshots as $snapshot) {
            $zoneKey = (string) $snapshot->zone_key;
            if (!isset($snapshotsByZone[$zoneKey])) {
                $snapshotsByZone[$zoneKey] = [];
            }

            $snapshotsByZone[$zoneKey][] = $snapshot;
        }

        foreach ($zones as &$zone) {
            $historySnapshots = $snapshotsByZone[$zone['id']] ?? [];

            $zone['history'] = array_map(function (MarketZoneSnapshot $snapshot) {
                return [
                    'month' => Carbon::parse($snapshot->snapshot_month)->format('Y-m'),
                    'avgSalePriceSqm' => round((float) ($snapshot->avg_sale_price_sqm ?? 0), 2),
                    'avgRentPriceSqm' => round((float) ($snapshot->avg_rent_price_sqm ?? 0), 2),
                    'avgMonthlyRent' => round((float) ($snapshot->avg_monthly_rent ?? 0), 2),
                    'opportunities' => (int) ($snapshot->opportunities_count ?? 0),
                ];
            }, $historySnapshots);

            $salePriceChangePct = 0.0;
            $rentPriceChangePct = 0.0;
            $opportunitiesChangePct = 0.0;

            if (count($historySnapshots) >= 2) {
                $last = $historySnapshots[count($historySnapshots) - 1];
                $prev = $historySnapshots[count($historySnapshots) - 2];

                $salePriceChangePct = $this->percentageChange(
                    (float) ($last->avg_sale_price_sqm ?? 0),
                    (float) ($prev->avg_sale_price_sqm ?? 0)
                );
                $rentPriceChangePct = $this->percentageChange(
                    (float) ($last->avg_rent_price_sqm ?? 0),
                    (float) ($prev->avg_rent_price_sqm ?? 0)
                );
                $opportunitiesChangePct = $this->percentageChange(
                    (float) ($last->opportunities_count ?? 0),
                    (float) ($prev->opportunities_count ?? 0)
                );
            }

            $direction = $salePriceChangePct > 1.2 ? 'up' : ($salePriceChangePct < -1.2 ? 'down' : 'stable');

            $zone['trend'] = [
                'direction' => $direction,
                'salePriceChangePct' => $salePriceChangePct,
                'rentPriceChangePct' => $rentPriceChangePct,
                'opportunitiesChangePct' => $opportunitiesChangePct,
            ];
        }
        unset($zone);

        return $zones;
    }

    /**
     * Agrega metricas por zona (ciudad/distrito/barrio) usando propiedades normalizadas.
     *
     * Formulas principales:
     * - avgSalePriceSqm = promedio(price / area_m2) con precio util de venta.
     * - avgRentPriceSqm = promedio(monthly_rent / area_m2) con monthly_rent > 0.
     * - avgMonthlyRent = promedio(monthly_rent) con monthly_rent > 0.
     * - estimatedYield = promedio(yield_gross) con yield_gross > 0.
     */
    private function aggregateZones(array $rows): array
    {
        $byZone = [];

        foreach ($rows as $row) {
            $location = $this->sanitizeLocation($row);
            $identity = $this->locationIdentity($location);

            $key = implode('|', [
                Str::lower($location['community']),
                Str::lower($location['province']),
                Str::lower($location['city']),
                Str::lower($location['district']),
                Str::lower($location['neighborhood']),
            ]);

            if (!isset($byZone[$key])) {
                $byZone[$key] = [
                    'id' => Str::slug($key),
                    'slug' => Str::slug($identity['name']),
                    'name' => $identity['name'],
                    'locationLevel' => $identity['level'],
                    'community' => $location['community'],
                    'province' => $location['province'],
                    'city' => $location['city'],
                    'district' => $location['district'],
                    'neighborhood' => $location['neighborhood'] !== 'N/D' ? $location['neighborhood'] : null,
                    'records' => [],
                ];
            }

            $byZone[$key]['records'][] = $row;
        }

        $zones = [];

        foreach ($byZone as $zone) {
            $records = $zone['records'];
            $count = count($records);

            $salePricePerSqm = collect($records)
                ->map(function (array $record) {
                    if (!$this->hasUsableSalePrice($record) || !is_numeric($record['area_m2']) || (float) $record['area_m2'] <= 0) {
                        return null;
                    }

                    return (float) $record['price'] / (float) $record['area_m2'];
                })
                ->filter(fn ($value) => $value !== null && $value > 0)
                ->values();

            $rentPricePerSqm = collect($records)
                ->map(function (array $record) {
                    if (!is_numeric($record['monthly_rent']) || !is_numeric($record['area_m2']) || (float) $record['area_m2'] <= 0) {
                        return null;
                    }

                    return (float) $record['monthly_rent'] / (float) $record['area_m2'];
                })
                ->filter(fn ($value) => $value !== null && $value > 0)
                ->values();

            $monthlyRentValues = collect($records)
                ->map(function (array $record) {
                    if (!is_numeric($record['monthly_rent']) || (float) $record['monthly_rent'] <= 0) {
                        return null;
                    }

                    return (float) $record['monthly_rent'];
                })
                ->filter(fn ($value) => $value !== null && $value > 0)
                ->values();

            $yieldValues = collect($records)
                ->map(fn (array $record) => is_numeric($record['yield_gross']) ? (float) $record['yield_gross'] : null)
                ->filter(fn ($value) => $value !== null && $value > 0 && $value <= self::MARKET_YIELD_MAX)
                ->values();

            $scoreValues = collect($records)
                ->map(fn (array $record) => is_numeric($record['investment_score']) ? (float) $record['investment_score'] : null)
                ->filter(fn ($value) => $value !== null)
                ->values();

            $operationCounts = [
                'sale' => 0,
                'rent' => 0,
            ];
            $assetTypeCounts = [];

            $opportunities = 0;

            foreach ($records as $record) {
                $resolvedOperation = $this->resolveOperation($record);
                if ($resolvedOperation !== 'rent') {
                    $operationCounts['sale'] += 1;
                }
                if ($resolvedOperation !== 'sale') {
                    $operationCounts['rent'] += 1;
                }

                $assetType = Str::lower($this->safeString($record['property_type'], 'activo'));
                $assetTypeCounts[$assetType] = ($assetTypeCounts[$assetType] ?? 0) + 1;

                $yieldValue = is_numeric($record['yield_gross']) ? (float) $record['yield_gross'] : 0;
                $scoreValue = is_numeric($record['investment_score']) ? (float) $record['investment_score'] : 0;

                if ($yieldValue > self::MARKET_YIELD_MAX) {
                    $yieldValue = 0;
                }

                if ($scoreValue >= self::OPPORTUNITY_SCORE_THRESHOLD || $yieldValue >= self::OPPORTUNITY_YIELD_THRESHOLD) {
                    $opportunities += 1;
                }
            }

            // Production rule: a market zone is valid only if it has sale inventory.
            if ($operationCounts['sale'] <= 0) {
                continue;
            }

            arsort($assetTypeCounts);
            $dominantAssetType = array_key_first($assetTypeCounts) ?? 'activo';
            $dominantOperation = $operationCounts['sale'] >= $operationCounts['rent'] ? 'sale' : 'rent';

            $avgSalePriceSqm = $salePricePerSqm->count() ? (float) $salePricePerSqm->avg() : 0.0;
            $avgRentPriceSqm = $rentPricePerSqm->count() ? (float) $rentPricePerSqm->avg() : 0.0;
            $avgMonthlyRent = $monthlyRentValues->count() ? (float) $monthlyRentValues->avg() : 0.0;
            $estimatedYield = $yieldValues->count() ? (float) $yieldValues->avg() : 0.0;
            $avgScore = $scoreValues->count() ? (float) $scoreValues->avg() : 0.0;

            $detailPath = '/inversion/' . trim($records[0]['region_slug'], '/');
            if ($zone['locationLevel'] === 'district' || $zone['locationLevel'] === 'neighborhood') {
                $detailPath .= '/' . Str::slug($zone['name']);
            }

            $zoneOutput = [
                'id' => $zone['id'],
                'slug' => $zone['slug'],
                'name' => $zone['name'],
                'locationLevel' => $zone['locationLevel'],
                'community' => $zone['community'],
                'province' => $zone['province'],
                'city' => $zone['city'],
                'district' => $zone['district'],
                'neighborhood' => $zone['neighborhood'],
                'operation' => $dominantOperation,
                'assetType' => $dominantAssetType,
                'avgSalePriceSqm' => round($avgSalePriceSqm, 2),
                'avgRentPriceSqm' => round($avgRentPriceSqm, 2),
                'avgMonthlyRent' => round($avgMonthlyRent, 2),
                'estimatedYield' => round($estimatedYield, 2),
                'investmentScore' => round($avgScore, 0),
                'opportunities' => $opportunities,
                'analyzedProperties' => $count,
                'trend' => [
                    'direction' => 'stable',
                    'salePriceChangePct' => 0,
                    'rentPriceChangePct' => 0,
                    'opportunitiesChangePct' => 0,
                ],
                'history' => [],
                'detailPath' => $detailPath,
            ];

            $zones[] = $zoneOutput;
        }

        return $zones;
    }

    private function sortZones(array $zones, Request $request): array
    {
        $sortBy = (string) $request->input('sortBy', 'investmentScore');
        $sortDir = strtolower((string) $request->input('sortDir', 'desc')) === 'asc' ? 'asc' : 'desc';

        usort($zones, function (array $left, array $right) use ($sortBy, $sortDir) {
            $valueLeft = $left[$sortBy] ?? $left['investmentScore'];
            $valueRight = $right[$sortBy] ?? $right['investmentScore'];

            if ($valueLeft === $valueRight) {
                return 0;
            }

            if ($sortDir === 'asc') {
                return $valueLeft <=> $valueRight;
            }

            return $valueRight <=> $valueLeft;
        });

        return $zones;
    }

    private function locationOptions(array $rows): array
    {
        $communities = [];
        $provinces = [];
        $cities = [];
        $districts = [];
        $neighborhoods = [];

        foreach ($rows as $row) {
            $location = $this->sanitizeLocation($row);

            if ($location['community'] !== 'N/D') {
                $communities[$location['community']] = true;
            }
            if ($location['province'] !== 'N/D') {
                $provinces[$location['province']] = true;
            }
            if ($location['city'] !== 'N/D') {
                $cities[$location['city']] = true;
            }
            if ($location['district'] !== 'N/D') {
                $districts[$location['district']] = true;
            }
            if ($location['neighborhood'] !== 'N/D') {
                $neighborhoods[$location['neighborhood']] = true;
            }
        }

        $sortFn = fn (string $left, string $right) => strcasecmp($left, $right);

        $communities = array_keys($communities);
        $provinces = array_keys($provinces);
        $cities = array_keys($cities);
        $districts = array_keys($districts);
        $neighborhoods = array_keys($neighborhoods);

        usort($communities, $sortFn);
        usort($provinces, $sortFn);
        usort($cities, $sortFn);
        usort($districts, $sortFn);
        usort($neighborhoods, $sortFn);

        return [
            'communities' => $communities,
            'provinces' => $provinces,
            'cities' => $cities,
            'districts' => $districts,
            'neighborhoods' => $neighborhoods,
        ];
    }

    private function summarize(array $zones): array
    {
        $zoneCount = count($zones);

        if ($zoneCount === 0) {
            return [
                'averageSalePriceSqm' => 0,
                'averageRentPriceSqm' => 0,
                'averageMonthlyRent' => 0,
                'averageYield' => 0,
                'averageInvestmentScore' => 0,
                'analyzedZones' => 0,
                'opportunitiesDetected' => 0,
            ];
        }

        $totalSale = array_sum(array_map(fn (array $zone) => (float) $zone['avgSalePriceSqm'], $zones));
        $totalRent = array_sum(array_map(fn (array $zone) => (float) $zone['avgRentPriceSqm'], $zones));
        $totalMonthlyRent = array_sum(array_map(fn (array $zone) => (float) ($zone['avgMonthlyRent'] ?? 0), $zones));
        $totalYield = array_sum(array_map(fn (array $zone) => (float) $zone['estimatedYield'], $zones));
        $totalScore = array_sum(array_map(fn (array $zone) => (float) $zone['investmentScore'], $zones));
        $totalOpportunities = array_sum(array_map(fn (array $zone) => (int) $zone['opportunities'], $zones));

        return [
            'averageSalePriceSqm' => round($totalSale / $zoneCount, 2),
            'averageRentPriceSqm' => round($totalRent / $zoneCount, 2),
            'averageMonthlyRent' => round($totalMonthlyRent / $zoneCount, 2),
            'averageYield' => round($totalYield / $zoneCount, 2),
            'averageInvestmentScore' => round($totalScore / $zoneCount, 1),
            'analyzedZones' => $zoneCount,
            'opportunitiesDetected' => $totalOpportunities,
        ];
    }

    private function seriesFromZones(array $zones): array
    {
        if (!$zones) {
            return [
                'categories' => [],
                'saleSeries' => [],
                'rentSeries' => [],
                'opportunitiesSeries' => [],
            ];
        }

        $bucket = [];

        foreach ($zones as $zone) {
            foreach ($zone['history'] as $point) {
                $month = $point['month'];
                if (!isset($bucket[$month])) {
                    $bucket[$month] = [
                        'sale' => 0,
                        'rent' => 0,
                        'opportunities' => 0,
                        'count' => 0,
                    ];
                }

                $bucket[$month]['sale'] += (float) $point['avgSalePriceSqm'];
                $bucket[$month]['rent'] += (float) $point['avgRentPriceSqm'];
                $bucket[$month]['opportunities'] += (float) $point['opportunities'];
                $bucket[$month]['count'] += 1;
            }
        }

        ksort($bucket);
        $categories = array_keys($bucket);

        return [
            'categories' => $categories,
            'saleSeries' => array_map(fn (string $month) => round($bucket[$month]['sale'] / max(1, $bucket[$month]['count']), 0), $categories),
            'rentSeries' => array_map(fn (string $month) => round($bucket[$month]['rent'] / max(1, $bucket[$month]['count']), 2), $categories),
            'opportunitiesSeries' => array_map(fn (string $month) => round($bucket[$month]['opportunities'], 0), $categories),
        ];
    }

    public function overview(Request $request): JsonResponse
    {
        $rows = $this->normalizeRows();
        $filteredRows = $this->applyFilters($rows, $request);
        $snapshotMonth = Carbon::now()->startOfMonth();
        $zones = $this->sortZones($this->withRealHistory($this->aggregateZones($filteredRows), $snapshotMonth), $request);
        $historyAvailable = (bool) collect($zones)->first(fn (array $zone) => count($zone['history']) > 0);

        return response()->json([
            'zones' => $zones,
            'summary' => $this->summarize($zones),
            'locationOptions' => $this->locationOptions($rows),
            'series' => $this->seriesFromZones($zones),
            'meta' => [
                'recordsAnalyzed' => count($filteredRows),
                'historySource' => 'real_snapshots',
                'historyAvailable' => $historyAvailable,
                'snapshotMonth' => $snapshotMonth->format('Y-m'),
            ],
        ]);
    }
}
