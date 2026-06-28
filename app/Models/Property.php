<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    protected $fillable = [
        'external_id',
        'source',
        'detail_url',
        'source_url',
        'title',
        'description',
        'property_type',
        'listing_mode',
        'lat',
        'lng',
        'price',
        'monthly_rent',
        'bedrooms',
        'bathrooms',
        'area_m2',
        'city',
        'region_slug',
        'yield_gross',
        'yield_net',
        'roi_annual',
        'investment_score',
        'images',
        'source_payload',
        'scraped_at',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'yield_gross' => 'float',
        'yield_net' => 'float',
        'roi_annual' => 'float',
        'images' => 'array',
        'source_payload' => 'array',
        'scraped_at' => 'datetime',
    ];

    public function favoritedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')
            ->withTimestamps();
    }
}
