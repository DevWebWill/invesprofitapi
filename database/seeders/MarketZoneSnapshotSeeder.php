<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketZoneSnapshotSeeder extends Seeder
{
    private function floatOrZero(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    public function run(): void
    {
        $communityExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(source_payload, '$.listing.address.regionLevel1')), 'null')";
        $provinceExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(source_payload, '$.listing.address.province')), 'null')";
        $cityExpr = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(source_payload, '$.listing.address.municipality')), 'null'), NULLIF(city, ''))";
        $districtExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(source_payload, '$.listing.address.district')), 'null')";
        $neighborhoodExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(source_payload, '$.listing.address.neighborhood')), 'null')";

        $zoneNameExpr = "COALESCE($neighborhoodExpr, $districtExpr, $cityExpr, region_slug)";

        $zones = DB::table('properties')
            ->selectRaw("$communityExpr as community")
            ->selectRaw("$provinceExpr as province")
            ->selectRaw("$cityExpr as city")
            ->selectRaw("$districtExpr as district")
            ->selectRaw("$neighborhoodExpr as neighborhood")
            ->selectRaw("$zoneNameExpr as zone_name")
            ->selectRaw('COUNT(*) as analyzed_properties_count')
            ->selectRaw("SUM(CASE WHEN investment_score >= 75 AND ((yield_gross IS NOT NULL AND yield_gross >= 5) OR monthly_rent > 0) THEN 1 ELSE 0 END) as opportunities_count")
            ->selectRaw('AVG(CASE WHEN price > 0 AND area_m2 > 0 THEN price / area_m2 END) as avg_sale_price_sqm')
            ->selectRaw('AVG(CASE WHEN monthly_rent > 0 AND area_m2 > 0 THEN monthly_rent / area_m2 END) as avg_rent_price_sqm')
            ->selectRaw('AVG(CASE WHEN yield_gross > 0 THEN yield_gross END) as estimated_yield')
            ->selectRaw('AVG(CASE WHEN investment_score > 0 THEN investment_score END) as investment_score')
            ->groupByRaw("$communityExpr, $provinceExpr, $cityExpr, $districtExpr, $neighborhoodExpr, $zoneNameExpr")
            ->get();

        if ($zones->isEmpty()) {
            $this->command?->warn('MarketZoneSnapshotSeeder: no hay propiedades para generar snapshots.');
            return;
        }

        $records = [];
        $now = now();

        foreach ($zones as $zone) {
            $zoneName = trim((string) ($zone->zone_name ?? 'Zona sin nombre'));
            $community = trim((string) ($zone->community ?? '')) ?: null;
            $province = trim((string) ($zone->province ?? '')) ?: null;
            $city = trim((string) ($zone->city ?? '')) ?: null;
            $district = trim((string) ($zone->district ?? '')) ?: null;
            $neighborhood = trim((string) ($zone->neighborhood ?? '')) ?: null;

            $locationLevel = $neighborhood !== null
                ? 'neighborhood'
                : ($district !== null ? 'district' : 'city');

            $zoneKey = Str::lower(implode('|', [
                $community ?? 'na',
                $province ?? 'na',
                $city ?? 'na',
                $district ?? 'na',
                $neighborhood ?? 'na',
            ]));

            $zoneSlug = Str::slug(($city ?? $province ?? 'zona') . '-' . $zoneName);
            if ($zoneSlug === '') {
                $zoneSlug = 'zona-' . substr(md5($zoneKey), 0, 8);
            }

            $saleCurrent = $this->floatOrZero($zone->avg_sale_price_sqm);
            $rentCurrent = $this->floatOrZero($zone->avg_rent_price_sqm);
            $yieldCurrent = $this->floatOrZero($zone->estimated_yield);
            $scoreCurrent = $this->floatOrZero($zone->investment_score);
            $propertiesCurrent = max(1, (int) ($zone->analyzed_properties_count ?? 0));
            $opportunitiesCurrent = max(0, (int) ($zone->opportunities_count ?? 0));

            $seed = abs(crc32($zoneKey));
            $saleChangePct = round($this->clamp((($seed % 190) / 10) - 4.0, -6.0, 15.0), 2);
            $rentChangePct = round($this->clamp((((int) ($seed / 7) % 220) / 10) - 3.0, -7.0, 19.0), 2);
            $opportunitiesChangePct = round($this->clamp((((int) ($seed / 11) % 260) / 10) - 5.0, -10.0, 22.0), 2);

            $saleStart = $saleCurrent > 0 ? max(0.1, $saleCurrent / (1 + ($saleChangePct / 100))) : 0;
            $rentStart = $rentCurrent > 0 ? max(0.1, $rentCurrent / (1 + ($rentChangePct / 100))) : 0;
            $oppStart = max(0, (int) round($opportunitiesCurrent / (1 + ($opportunitiesChangePct / 100))));
            $scoreStart = $this->clamp($scoreCurrent - ($saleChangePct * 0.55), 0, 100);
            $yieldStart = max(0, $yieldCurrent - ($rentChangePct * 0.06));

            for ($offset = 11; $offset >= 0; $offset -= 1) {
                $index = 11 - $offset;
                $progress = $index / 11;
                $snapshotMonth = Carbon::now()->startOfMonth()->subMonths($offset);

                $avgSale = $saleCurrent > 0
                    ? $saleStart + (($saleCurrent - $saleStart) * $progress)
                    : 0;

                $avgRent = $rentCurrent > 0
                    ? $rentStart + (($rentCurrent - $rentStart) * $progress)
                    : 0;

                $opportunities = (int) round($oppStart + (($opportunitiesCurrent - $oppStart) * $progress));
                $score = $this->clamp($scoreStart + (($scoreCurrent - $scoreStart) * $progress), 0, 100);
                $yield = max(0, $yieldStart + (($yieldCurrent - $yieldStart) * $progress));

                $records[] = [
                    'snapshot_month' => $snapshotMonth->toDateString(),
                    'zone_key' => $zoneKey,
                    'zone_slug' => $zoneSlug,
                    'zone_name' => $zoneName,
                    'location_level' => $locationLevel,
                    'community' => $community,
                    'province' => $province,
                    'city' => $city,
                    'district' => $district,
                    'neighborhood' => $neighborhood,
                    'operation' => 'all',
                    'asset_type' => 'all',
                    'analyzed_properties_count' => $propertiesCurrent,
                    'opportunities_count' => max(0, $opportunities),
                    'avg_sale_price_sqm' => $avgSale > 0 ? round($avgSale, 2) : null,
                    'avg_rent_price_sqm' => $avgRent > 0 ? round($avgRent, 2) : null,
                    'estimated_yield' => round($yield, 2),
                    'investment_score' => round($score, 2),
                    'sale_price_change_pct' => $saleChangePct,
                    'rent_price_change_pct' => $rentChangePct,
                    'opportunities_change_pct' => $opportunitiesChangePct,
                    'data_source' => 'synthetic',
                    'meta' => json_encode([
                        'generator' => 'MarketZoneSnapshotSeeder',
                        'version' => 1,
                        'seed' => $seed,
                        'note' => 'Synthetic monthly reconstruction from current zone aggregates.',
                    ], JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('market_zone_snapshots')->upsert(
            $records,
            ['snapshot_month', 'zone_key', 'operation', 'asset_type'],
            [
                'zone_slug',
                'zone_name',
                'location_level',
                'community',
                'province',
                'city',
                'district',
                'neighborhood',
                'analyzed_properties_count',
                'opportunities_count',
                'avg_sale_price_sqm',
                'avg_rent_price_sqm',
                'estimated_yield',
                'investment_score',
                'sale_price_change_pct',
                'rent_price_change_pct',
                'opportunities_change_pct',
                'data_source',
                'meta',
                'updated_at',
            ]
        );

        $this->command?->info('MarketZoneSnapshotSeeder: snapshots sinteticos generados/actualizados: ' . count($records));
    }
}
