<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RawSaleProperty extends Model
{
    protected $table = 'raw_sale_properties';

    protected $fillable = [
        'source',
        'external_id',
        'listing_mode',
        'title',
        'description',
        'property_type',
        'location',
        'city',
        'region_slug',
        'lat',
        'lng',
        'price_text',
        'price_value',
        'price',
        'monthly_rent',
        'bedrooms',
        'bathrooms',
        'area_m2',
        'detail_url',
        'source_url',
        'images',
        'downloaded_images',
        'source_payload',
        'raw_payload',
        'scraped_at',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'images' => 'array',
        'downloaded_images' => 'array',
        'source_payload' => 'array',
        'raw_payload' => 'array',
        'scraped_at' => 'datetime',
    ];
}
