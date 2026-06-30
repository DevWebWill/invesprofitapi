<?php

namespace Tests\Unit;

use App\Exceptions\CatastroRequestException;
use App\Services\Catastro\CatastroService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatastroServiceTest extends TestCase
{
    public function test_it_requests_and_normalizes_provincias(): void
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

        $response = app(CatastroService::class)->obtenerProvincias();

        $this->assertSame([
            ['code' => '28', 'name' => 'MADRID'],
        ], $response);

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://ovc.catastro.meh.es/OVCServWeb/OVCWcfCallejero/COVCCallejero.svc/json/ObtenerProvincias');
    }

    public function test_it_requests_and_normalizes_municipios(): void
    {
        config()->set('services.catastro.base_url', 'https://ovc.catastro.meh.es/OVCServWeb/OVCWcfCallejero/COVCCallejero.svc/json');

        Http::fake([
            '*ObtenerMunicipios*' => Http::response([
                'consulta_municipieroResult' => [
                    'municipiero' => [
                        'muni' => [
                            ['loine' => ['cm' => '190'], 'nm' => 'PATERNA'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = app(CatastroService::class)->obtenerMunicipios('VALENCIA');

        $this->assertSame([
            ['code' => '190', 'name' => 'PATERNA'],
        ], $response);

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'ObtenerMunicipios?Provincia=VALENCIA&Municipio='));
    }

    public function test_it_applies_minimum_length_for_callejero_search(): void
    {
        config()->set('services.catastro.base_url', 'https://ovc.catastro.meh.es/OVCServWeb/OVCWcfCallejero/COVCCallejero.svc/json');

        Http::preventStrayRequests();

        $response = app(CatastroService::class)->obtenerCallejero('VALENCIA', 'PATERNA', 'sa');

        $this->assertSame([], $response);
        Http::assertNothingSent();
    }

    public function test_it_requests_and_normalizes_callejero(): void
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

        $response = app(CatastroService::class)->obtenerCallejero('VALENCIA', 'PATERNA', 'SANT');

        $this->assertSame([
            [
                'code' => '115',
                'name' => 'SANT FRANCESC DE BORJA',
                'street_type' => 'CL',
                'label' => 'CL SANT FRANCESC DE BORJA',
            ],
        ], $response);
    }

    public function test_it_builds_parcel_reference_from_numerero(): void
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

        $response = app(CatastroService::class)->obtenerNumerero('VALENCIA', 'PATERNA', 'CL', 'SANT FRANCESC DE BORJA', '49');

        $this->assertSame([
            ['number' => '49', 'parcel_reference' => '0062704YJ2706S'],
        ], $response);
    }

    public function test_it_builds_property_dto_list_from_dnprc(): void
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

        $response = app(CatastroService::class)->consultarPropiedadesParcela('VALENCIA', 'PATERNA', '0062704YJ2706S');

        $this->assertSame('0062704YJ2706S0009JP', $response[0]['reference']);
        $this->assertSame('0062704YJ2706S', $response[0]['parcel_reference']);
        $this->assertSame('02', $response[0]['floor']);
        $this->assertSame('07', $response[0]['door']);
        $this->assertSame('S', $response[0]['stair']);
        $this->assertSame(102, $response[0]['surface']);
        $this->assertSame('Residencial', $response[0]['usage']);
        $this->assertSame(1974, $response[0]['year']);
    }

    public function test_it_throws_domain_exception_for_upstream_errors(): void
    {
        config()->set('services.catastro.base_url', 'https://ovc.catastro.meh.es/OVCServWeb/OVCWcfCallejero/COVCCallejero.svc/json');

        Http::fake([
            '*ObtenerProvincias*' => Http::response([
                'error' => 'catastro unavailable',
            ], 500),
        ]);

        try {
            app(CatastroService::class)->obtenerProvincias();
            $this->fail('Expected CatastroRequestException to be thrown.');
        } catch (CatastroRequestException $exception) {
            $this->assertSame(500, $exception->statusCode());
            $this->assertStringContainsString('catastro unavailable', (string) $exception->responseBody());
        }
    }
}
