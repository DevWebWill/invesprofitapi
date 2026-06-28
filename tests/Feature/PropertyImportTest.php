<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PropertyImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_endpoint_stores_property_and_images(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload = [
            'external_id' => 'fotocasa-123',
            'source' => 'fotocasa',
            'title' => 'Flat · 2 hab. · 80 m²',
            'description' => 'Property description',
            'property_type' => 'piso',
            'listing_mode' => 'sale',
            'lat' => 40.4168,
            'lng' => -3.7038,
            'price' => 350000,
            'monthly_rent' => null,
            'bedrooms' => 2,
            'bathrooms' => 1,
            'area_m2' => 80,
            'city' => 'Madrid',
            'region_slug' => 'madrid',
            'yield_gross' => 4.8,
            'yield_net' => 3.7,
            'investment_score' => 72,
            'detail_url' => 'https://example.com/detail',
            'source_url' => 'https://example.com/list',
            'scraped_at' => now()->toIso8601String(),
        ];

        $response = $this->postJson('/api/properties/import', [
            'payload' => json_encode($payload),
            'images' => [
                UploadedFile::fake()->image('front.jpg'),
                UploadedFile::fake()->image('living-room.png'),
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.external_id', 'fotocasa-123');

        $property = Property::query()->where('external_id', 'fotocasa-123')->firstOrFail();

        $this->assertCount(2, $property->images ?? []);
        $this->assertTrue(Storage::disk('public')->exists($property->images[0]));
        $this->assertTrue(Storage::disk('public')->exists($property->images[1]));
        $this->assertSame('fotocasa', $property->source);
        $this->assertSame('Madrid', $property->city);
    }

    public function test_import_endpoint_updates_existing_property(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload = [
            'external_id' => 'fotocasa-123',
            'source' => 'fotocasa',
            'title' => 'Flat · 2 hab. · 80 m²',
            'description' => 'Property description',
            'property_type' => 'piso',
            'listing_mode' => 'sale',
            'lat' => 40.4168,
            'lng' => -3.7038,
            'price' => 350000,
            'monthly_rent' => null,
            'bedrooms' => 2,
            'bathrooms' => 1,
            'area_m2' => 80,
            'city' => 'Madrid',
            'region_slug' => 'madrid',
            'yield_gross' => 4.8,
            'yield_net' => 3.7,
            'investment_score' => 72,
            'detail_url' => 'https://example.com/detail',
            'source_url' => 'https://example.com/list',
            'scraped_at' => now()->toIso8601String(),
        ];

        $this->postJson('/api/properties/import', [
            'payload' => json_encode($payload),
        ])->assertCreated();

        $updated = array_merge($payload, ['price' => 360000, 'lat' => 40.42, 'lng' => -3.71]);

        $response = $this->postJson('/api/properties/import', [
            'payload' => json_encode($updated),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.external_id', 'fotocasa-123');
        $this->assertSame(1, Property::query()->where('external_id', 'fotocasa-123')->count());
        $this->assertSame(360000, Property::query()->where('external_id', 'fotocasa-123')->value('price'));
    }

    public function test_import_endpoint_stores_rent_listings_without_yield_overflow(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload = [
            'external_id' => 'fotocasa-rent-123',
            'source' => 'fotocasa',
            'title' => 'Flat for rent · 2 hab. · 80 m²',
            'description' => 'Property description',
            'property_type' => 'piso',
            'listing_mode' => 'rent',
            'lat' => 40.4168,
            'lng' => -3.7038,
            'price' => 1995,
            'monthly_rent' => 1995,
            'bedrooms' => 2,
            'bathrooms' => 1,
            'area_m2' => 80,
            'city' => 'Madrid',
            'region_slug' => 'madrid',
            'yield_gross' => 0,
            'yield_net' => 0,
            'investment_score' => 0,
            'detail_url' => 'https://example.com/detail-rent',
            'source_url' => 'https://example.com/list-rent',
            'scraped_at' => now()->toIso8601String(),
        ];

        $response = $this->postJson('/api/properties/import', [
            'payload' => json_encode($payload),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.listing_mode', 'rent');

        $property = Property::query()->where('external_id', 'fotocasa-rent-123')->firstOrFail();

        $this->assertSame('rent', $property->listing_mode);
        $this->assertSame(0.0, (float) $property->yield_gross);
        $this->assertSame(0.0, (float) $property->yield_net);
    }

    public function test_sale_listing_with_estimated_rent_stays_sale_in_api_response(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        foreach ([1200, 1250, 1300] as $index => $monthlyRent) {
            Property::query()->create([
                'external_id' => 'rent-comp-' . $index,
                'source' => 'fotocasa',
                'title' => 'Rent comparable ' . $index,
                'description' => 'Comparable rent listing',
                'property_type' => 'piso',
                'listing_mode' => 'rent',
                'lat' => 40.4168 + ($index * 0.001),
                'lng' => -3.7038 + ($index * 0.001),
                'price' => 0,
                'monthly_rent' => $monthlyRent,
                'bedrooms' => 2,
                'bathrooms' => 1,
                'area_m2' => 80,
                'city' => 'Madrid',
                'region_slug' => 'madrid',
                'yield_gross' => 0,
                'yield_net' => 0,
                'investment_score' => 0,
                'images' => [],
                'source_payload' => [],
                'scraped_at' => now(),
            ]);
        }

        $payload = [
            'external_id' => 'fotocasa-sale-estimated-rent',
            'source' => 'fotocasa',
            'title' => 'Flat for sale · 2 hab. · 80 m²',
            'description' => 'Property description',
            'property_type' => 'piso',
            'listing_mode' => 'sale',
            'lat' => 40.4168,
            'lng' => -3.7038,
            'price' => 350000,
            'monthly_rent' => null,
            'bedrooms' => 2,
            'bathrooms' => 1,
            'area_m2' => 80,
            'city' => 'Madrid',
            'region_slug' => 'madrid',
            'yield_gross' => 0,
            'yield_net' => 0,
            'investment_score' => 0,
            'detail_url' => 'https://example.com/detail-sale',
            'source_url' => 'https://example.com/list-sale',
            'scraped_at' => now()->toIso8601String(),
        ];

        $response = $this->postJson('/api/properties/import', [
            'payload' => json_encode($payload),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.listing_mode', 'sale');
        $this->assertGreaterThan(0, (int) $response->json('data.monthly_rent'));
    }
}
