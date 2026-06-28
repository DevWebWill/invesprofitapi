<?php

namespace Tests\Feature;

use App\Models\RawRentProperty;
use App\Models\RawSaleProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RawPropertyImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_raw_import_stores_unmodified_price_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload = [
            'external_id' => 'fotocasa-sale-raw-1',
            'source' => 'fotocasa',
            'listing_mode' => 'sale',
            'title' => 'Piso en venta',
            'price_text' => '350.000 EUR',
            'price_value' => 350000,
            'price' => 350000,
            'monthly_rent' => null,
            'detail_url' => 'https://example.com/sale/1',
            'source_payload' => ['listing' => ['id' => 1]],
            'scraped_at' => now()->toIso8601String(),
        ];

        $response = $this->postJson('/api/raw-properties/sale/import', [
            'payload' => json_encode($payload),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.listing_mode', 'sale');

        $record = RawSaleProperty::query()->where('external_id', 'fotocasa-sale-raw-1')->firstOrFail();
        $this->assertSame(350000, $record->price_value);
        $this->assertSame(350000, $record->price);
        $this->assertNull($record->monthly_rent);
        $this->assertSame($payload['price_text'], $record->price_text);
        $this->assertSame($payload['source_payload'], $record->source_payload);
    }

    public function test_rent_raw_import_keeps_portal_price_without_normalizing_sale_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload = [
            'external_id' => 'fotocasa-rent-raw-1',
            'source' => 'fotocasa',
            'listing_mode' => 'rent',
            'title' => 'Piso en alquiler',
            'price_text' => '1.500 EUR/mes',
            'price_value' => 1500,
            'price' => 1500,
            'monthly_rent' => null,
            'detail_url' => 'https://example.com/rent/1',
            'source_payload' => ['listing' => ['id' => 2]],
            'scraped_at' => now()->toIso8601String(),
        ];

        $response = $this->postJson('/api/raw-properties/rent/import', [
            'payload' => json_encode($payload),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.listing_mode', 'rent');

        $record = RawRentProperty::query()->where('external_id', 'fotocasa-rent-raw-1')->firstOrFail();
        $this->assertSame(1500, $record->price_value);
        $this->assertSame(1500, $record->price);
        $this->assertNull($record->monthly_rent);
        $this->assertSame($payload['price_text'], $record->price_text);
        $this->assertSame($payload, $record->raw_payload);
    }
}
