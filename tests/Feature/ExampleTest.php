<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_the_root_route_is_not_available(): void
    {
        $response = $this->get('/');

        $response->assertNotFound();
    }

    public function test_nominatim_search_returns_empty_array_when_query_is_missing(): void
    {
        Http::preventStrayRequests();

        $response = $this->getJson('/api/geocoding/nominatim/search');

        $response->assertOk()->assertExactJson([]);

        Http::assertNothingSent();
    }

    public function test_nominatim_search_proxies_the_request_with_the_expected_contract(): void
    {
        $payload = [
            [
                'place_id' => 123,
                'display_name' => 'Madrid, Comunidad de Madrid, Espana',
                'lat' => '40.4167',
                'lon' => '-3.70325',
            ],
        ];

        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response($payload, 200),
        ]);

        $response = $this->getJson('/api/geocoding/nominatim/search?q=Madrid&limit=99&accept-language=es');

        $response->assertOk()->assertExactJson($payload);

        Http::assertSent(function (ClientRequest $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://nominatim.openstreetmap.org/search?q=Madrid&format=jsonv2&addressdetails=1&polygon_geojson=1&limit=10&accept-language=es'
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('Accept-Language', 'es');
        });
    }

    public function test_properties_index_returns_all_seed_data(): void
    {
        $response = $this->getJson('/api/properties');

        $response->assertOk()
            ->assertJsonCount(8)
            ->assertJsonFragment([
                'external_id' => 'prop-mad-1',
                'city' => 'Madrid',
            ])
            ->assertJsonFragment([
                'external_id' => 'prop-mal-1',
                'city' => 'Malaga',
            ]);
    }

    public function test_properties_index_applies_price_filter(): void
    {
        $response = $this->getJson('/api/properties?priceMax=260000&propertyTypes[]=piso');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.external_id', 'prop-mad-3');
    }

    public function test_properties_index_applies_yield_filter(): void
    {
        $response = $this->getJson('/api/properties?yieldMin=6');

        $response->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.external_id', 'prop-mad-3')
            ->assertJsonPath('1.external_id', 'prop-mal-1');
    }

    public function test_properties_show_returns_a_single_property(): void
    {
        $response = $this->getJson('/api/properties/prop-mad-1');

        $response->assertOk()
            ->assertJsonPath('external_id', 'prop-mad-1')
            ->assertJsonPath('title', 'Chalet pareado en Tetuan')
            ->assertJsonPath('city', 'Madrid');
    }

    public function test_properties_show_returns_not_found_for_unknown_external_id(): void
    {
        $response = $this->getJson('/api/properties/no-existe');

        $response->assertNotFound();
    }
}
