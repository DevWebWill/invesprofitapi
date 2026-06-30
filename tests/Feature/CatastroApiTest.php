<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatastroApiTest extends TestCase
{
    public function test_location_provinces_endpoint_returns_normalized_data(): void
    {
        config()->set('services.catastro.base_url', 'https://ovc.catastro.meh.es/OVCServWeb/OVCWcfCallejero/COVCCallejero.svc/json');

        Http::fake([
            '*ObtenerProvincias*' => Http::response([
                'consulta_provincieroResult' => [
                    'provinciero' => [
                        'prov' => [
                            ['cpine' => '28', 'np' => 'MADRID'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/location/provinces');

        $response->assertOk()->assertJson([
            ['code' => '28', 'name' => 'MADRID'],
        ]);
    }

    public function test_location_municipalities_requires_province_query(): void
    {
        Http::preventStrayRequests();

        $response = $this->getJson('/api/location/municipalities');

        $response->assertStatus(422)->assertJsonValidationErrors([
            'province',
        ]);

        Http::assertNothingSent();
    }

    public function test_location_streets_requires_minimum_search_length(): void
    {
        Http::preventStrayRequests();

        $response = $this->getJson('/api/location/streets?province=VALENCIA&municipality=PATERNA&search=sa');

        $response->assertStatus(422)->assertJsonValidationErrors([
            'search',
        ]);

        Http::assertNothingSent();
    }

    public function test_location_numbers_returns_parcel_references(): void
    {
        config()->set('services.catastro.base_url', 'https://ovc.catastro.meh.es/OVCServWeb/OVCWcfCallejero/COVCCallejero.svc/json');

        Http::fake([
            '*ObtenerNumerero*' => Http::response([
                'consulta_numereroResult' => [
                    'nump' => [
                        ['pc' => ['pc1' => '0062704', 'pc2' => 'YJ2706S'], 'num' => ['pnp' => '49']],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/location/numbers?province=VALENCIA&municipality=PATERNA&street_type=CL&street=SANT%20FRANCESC%20DE%20BORJA&number=49');

        $response->assertOk()->assertJson([
            ['number' => '49', 'parcel_reference' => '0062704YJ2706S'],
        ]);
    }

    public function test_location_properties_returns_reference_list(): void
    {
        config()->set('services.catastro.base_url', 'https://ovc.catastro.meh.es/OVCServWeb/OVCWcfCallejero/COVCCallejero.svc/json');

        Http::fake([
            '*Consulta_DNPRC*' => Http::response([
                'consulta_dnprcResult' => [
                    'lrcdnp' => [
                        'rcdnp' => [
                            [
                                'rc' => ['pc1' => '0062704', 'pc2' => 'YJ2706S', 'car' => '0009', 'cc1' => 'J', 'cc2' => 'P'],
                                'dt' => [
                                    'np' => 'VALENCIA',
                                    'nm' => 'PATERNA',
                                    'locs' => ['lous' => ['lourb' => ['dir' => ['tv' => 'CL', 'nv' => 'SANT FRANCESC DE BORJA', 'pnp' => '49'], 'loint' => ['es' => 'S', 'pt' => '02', 'pu' => '07'], 'dp' => '46980']]],
                                ],
                                'debi' => ['luso' => 'Residencial', 'sfc' => '102', 'ant' => '1974'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/location/properties?parcel_reference=0062704YJ2706S&province=VALENCIA&municipality=PATERNA');

        $response->assertOk()->assertJsonFragment([
            'reference' => '0062704YJ2706S0009JP',
            'floor' => '02',
            'door' => '07',
            'stair' => 'S',
            'surface' => 102,
            'usage' => 'Residencial',
            'year' => 1974,
        ]);
    }

    public function test_location_streets_hits_upstream_with_expected_query(): void
    {
        config()->set('services.catastro.base_url', 'https://ovc.catastro.meh.es/OVCServWeb/OVCWcfCallejero/COVCCallejero.svc/json');

        Http::fake([
            '*ObtenerCallejero*' => Http::response([
                'consulta_callejeroResult' => [
                    'callejero' => [
                        'calle' => [
                            ['dir' => ['cv' => '115', 'tv' => 'CL', 'nv' => 'SANT FRANCESC DE BORJA']],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->getJson('/api/location/streets?province=VALENCIA&municipality=PATERNA&search=SANT')->assertOk();

        Http::assertSent(function (ClientRequest $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), 'ObtenerCallejero?Provincia=VALENCIA&Municipio=PATERNA&TipoVia=&NomVia=SANT');
        });
    }
}
