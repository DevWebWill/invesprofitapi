<?php

use App\Http\Controllers\Api\MarketController;
use App\Models\MarketZoneSnapshot;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('markets:snapshot {--month=} {--overwrite}', function () {
    $monthInput = (string) ($this->option('month') ?? '');
    $snapshotMonth = $monthInput !== ''
        ? Carbon::parse($monthInput)->startOfMonth()
        : Carbon::now()->startOfMonth();

    $controller = app(MarketController::class);
    $overview = $controller->overview(Request::create('/api/markets/overview', 'GET'))->getData(true);
    $zones = is_array($overview['zones'] ?? null) ? $overview['zones'] : [];

    if (!$zones) {
        $this->warn('No zones available. Snapshot was not created.');
        return;
    }

    DB::transaction(function () use ($snapshotMonth, $zones) {
        if ((bool) $this->option('overwrite')) {
            MarketZoneSnapshot::query()
                ->whereDate('snapshot_month', $snapshotMonth->toDateString())
                ->where('operation', 'all')
                ->where('asset_type', 'all')
                ->delete();
        }

        foreach ($zones as $zone) {
            $zoneKey = (string) ($zone['id'] ?? '');
            if ($zoneKey === '') {
                continue;
            }

            MarketZoneSnapshot::query()->updateOrCreate(
                [
                    'snapshot_month' => $snapshotMonth->toDateString(),
                    'zone_key' => $zoneKey,
                    'operation' => 'all',
                    'asset_type' => 'all',
                ],
                [
                    'zone_slug' => (string) ($zone['slug'] ?? $zoneKey),
                    'zone_name' => (string) ($zone['name'] ?? $zoneKey),
                    'location_level' => (string) ($zone['locationLevel'] ?? 'city'),
                    'community' => $zone['community'] ?? null,
                    'province' => $zone['province'] ?? null,
                    'city' => $zone['city'] ?? null,
                    'district' => $zone['district'] ?? null,
                    'neighborhood' => $zone['neighborhood'] ?? null,
                    'analyzed_properties_count' => (int) ($zone['analyzedProperties'] ?? 0),
                    'opportunities_count' => (int) ($zone['opportunities'] ?? 0),
                    'avg_sale_price_sqm' => (float) ($zone['avgSalePriceSqm'] ?? 0),
                    'avg_rent_price_sqm' => (float) ($zone['avgRentPriceSqm'] ?? 0),
                    'avg_monthly_rent' => (float) ($zone['avgMonthlyRent'] ?? 0),
                    'estimated_yield' => (float) ($zone['estimatedYield'] ?? 0),
                    'investment_score' => (float) ($zone['investmentScore'] ?? 0),
                    'sale_price_change_pct' => null,
                    'rent_price_change_pct' => null,
                    'opportunities_change_pct' => null,
                    'data_source' => 'real',
                    'meta' => [
                        'captured_at' => Carbon::now()->toIso8601String(),
                        'captured_from' => 'properties_live_aggregate',
                    ],
                ]
            );
        }
    });

    $stored = MarketZoneSnapshot::query()
        ->whereDate('snapshot_month', $snapshotMonth->toDateString())
        ->where('operation', 'all')
        ->where('asset_type', 'all')
        ->where('data_source', 'real')
        ->count();

    $this->info(sprintf(
        'Market snapshot stored for %s. Zones saved: %d',
        $snapshotMonth->format('Y-m'),
        $stored
    ));
})->purpose('Capture a real monthly market snapshot from current properties dataset');

Schedule::command('markets:snapshot')
    ->monthlyOn(1, '02:15')
    ->withoutOverlapping()
    ->name('markets-monthly-snapshot');
