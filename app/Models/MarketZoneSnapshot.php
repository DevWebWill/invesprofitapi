<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketZoneSnapshot extends Model
{
    protected $fillable = [
        'snapshot_month',
        'zone_key',
        'zone_slug',
        'zone_name',
        'location_level',
        'community',
        'province',
        'city',
        'district',
        'neighborhood',
        'operation',
        'asset_type',
        'analyzed_properties_count',
        'opportunities_count',
        'avg_sale_price_sqm',
        'avg_rent_price_sqm',
        'avg_monthly_rent',
        'estimated_yield',
        'investment_score',
        'sale_price_change_pct',
        'rent_price_change_pct',
        'opportunities_change_pct',
        'data_source',
        'meta',
    ];

    protected $casts = [
        'snapshot_month' => 'date',
        'avg_sale_price_sqm' => 'float',
        'avg_rent_price_sqm' => 'float',
        'avg_monthly_rent' => 'float',
        'estimated_yield' => 'float',
        'investment_score' => 'float',
        'sale_price_change_pct' => 'float',
        'rent_price_change_pct' => 'float',
        'opportunities_change_pct' => 'float',
        'meta' => 'array',
    ];
}
