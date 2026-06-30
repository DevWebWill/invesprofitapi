<?php

namespace App\Services\Catastro;

use App\DataTransferObjects\PropertyDTO;
use App\Exceptions\CatastroRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

class CatastroService
{
    public function __construct(
        private readonly Factory $http,
    ) {
    }

    public function obtenerProvincias(array $headers = []): array
    {
        $response = $this->requestJson('GET', '/ObtenerProvincias', headers: $headers);

        return array_values(array_filter(array_map(static function (array $item): ?array {
            $code = trim((string) data_get($item, 'cpine', ''));
            $name = trim((string) data_get($item, 'np', ''));

            if ($code === '' || $name === '') {
                return null;
            }

            return [
                'code' => $code,
                'name' => $name,
            ];
        }, $this->listify(data_get($response, 'consulta_provincieroResult.provinciero.prov', [])))));
    }

    public function obtenerMunicipios(string $provincia, array $headers = []): array
    {
        $response = $this->requestJson(
            'GET',
            '/ObtenerMunicipios',
            query: [
                'Provincia' => trim($provincia),
                'Municipio' => '',
            ],
            headers: $headers,
        );

        return array_values(array_filter(array_map(static function (array $item): ?array {
            $code = trim((string) data_get($item, 'loine.cm', data_get($item, 'locat.cmc', '')));
            $name = trim((string) data_get($item, 'nm', ''));

            if ($code === '' || $name === '') {
                return null;
            }

            return [
                'code' => $code,
                'name' => $name,
            ];
        }, $this->listify(data_get($response, 'consulta_municipieroResult.municipiero.muni', [])))));
    }

    public function obtenerCallejero(
        string $provincia,
        string $municipio,
        string $search,
        array $headers = [],
    ): array {
        $needle = trim($search);

        if (mb_strlen($needle) < 3) {
            return [];
        }

        $response = $this->requestJson(
            'GET',
            '/ObtenerCallejero',
            query: [
                'Provincia' => trim($provincia),
                'Municipio' => trim($municipio),
                'TipoVia' => '',
                'NomVia' => $needle,
            ],
            headers: $headers,
        );

        $rows = [];

        foreach ($this->listify(data_get($response, 'consulta_callejeroResult.callejero.calle', [])) as $street) {
            $code = trim((string) data_get($street, 'dir.cv', ''));
            $type = trim((string) data_get($street, 'dir.tv', ''));
            $name = trim((string) data_get($street, 'dir.nv', ''));

            if ($code === '' || $name === '') {
                continue;
            }

            $rows[$code] = [
                'code' => $code,
                'name' => $name,
                'street_type' => $type,
                'label' => trim(sprintf('%s %s', $type, $name)),
            ];
        }

        return array_values($rows);
    }

    public function obtenerTiposVia(
        string $provincia,
        string $municipio,
        string $tipoVia = '',
        string $nombreVia = '',
        array $headers = [],
    ): array {
        $streets = $this->obtenerCallejero(
            provincia: $provincia,
            municipio: $municipio,
            search: $nombreVia,
            headers: $headers,
        );

        $types = [];

        foreach ($streets as $street) {
            $streetType = trim((string) data_get($street, 'street_type', ''));

            if ($streetType !== '') {
                $types[$streetType] = [
                    'code' => $streetType,
                    'name' => $streetType,
                ];
            }
        }

        return [
            'tipos_via' => array_values($types),
            'vias' => array_map(static fn (array $street): array => [
                'code' => (string) data_get($street, 'code', ''),
                'name' => (string) data_get($street, 'name', ''),
                'tipoVia' => (string) data_get($street, 'street_type', ''),
                'label' => (string) data_get($street, 'label', ''),
            ], $streets),
            'consulta_via_result' => [],
        ];
    }

    public function obtenerNumerero(
        string $provincia,
        string $municipio,
        string $tipoVia,
        string $nomVia,
        string $numero = '',
        array $headers = [],
    ): array {
        $response = $this->requestJson(
            'GET',
            '/ObtenerNumerero',
            query: [
                'Provincia' => trim($provincia),
                'Municipio' => trim($municipio),
                'TipoVia' => trim($tipoVia),
                'NomVia' => trim($nomVia),
                'Numero' => trim($numero),
            ],
            headers: $headers,
        );

        $rows = [];

        foreach ($this->listify(data_get($response, 'consulta_numereroResult.nump', [])) as $entry) {
            $number = trim((string) data_get($entry, 'num.pnp', ''));
            $pc1 = trim((string) data_get($entry, 'pc.pc1', ''));
            $pc2 = trim((string) data_get($entry, 'pc.pc2', ''));

            if ($number === '' || $pc1 === '' || $pc2 === '') {
                continue;
            }

            $rows[$number] = [
                'number' => $number,
                'parcel_reference' => $pc1.$pc2,
            ];
        }

        return array_values($rows);
    }

    public function consultarPropiedadesParcela(
        string $provincia,
        string $municipio,
        string $parcelReference,
        array $headers = [],
    ): array {
        $response = $this->requestJson(
            'GET',
            '/Consulta_DNPRC',
            query: [
                'Provincia' => trim($provincia),
                'Municipio' => trim($municipio),
                'RefCat' => trim($parcelReference),
            ],
            headers: $headers,
        );

        $properties = [];

        foreach ($this->listify(data_get($response, 'consulta_dnprcResult.lrcdnp.rcdnp', [])) as $rawProperty) {
            $pc1 = trim((string) data_get($rawProperty, 'rc.pc1', ''));
            $pc2 = trim((string) data_get($rawProperty, 'rc.pc2', ''));
            $car = trim((string) data_get($rawProperty, 'rc.car', ''));
            $cc1 = trim((string) data_get($rawProperty, 'rc.cc1', ''));
            $cc2 = trim((string) data_get($rawProperty, 'rc.cc2', ''));

            if ($pc1 === '' || $pc2 === '') {
                continue;
            }

            $dto = new PropertyDTO(
                reference: $pc1.$pc2.$car.$cc1.$cc2,
                parcelReference: $pc1.$pc2,
                province: trim((string) data_get($rawProperty, 'dt.np', $provincia)),
                municipality: trim((string) data_get($rawProperty, 'dt.nm', $municipio)),
                postalCode: trim((string) data_get($rawProperty, 'dt.locs.lous.lourb.dp', '')),
                street: trim((string) data_get($rawProperty, 'dt.locs.lous.lourb.dir.nv', '')),
                streetType: trim((string) data_get($rawProperty, 'dt.locs.lous.lourb.dir.tv', '')),
                number: trim((string) data_get($rawProperty, 'dt.locs.lous.lourb.dir.pnp', '')),
                block: trim((string) data_get($rawProperty, 'dt.locs.lous.lourb.loint.bq', '')),
                stair: trim((string) data_get($rawProperty, 'dt.locs.lous.lourb.loint.es', '')),
                floor: trim((string) data_get($rawProperty, 'dt.locs.lous.lourb.loint.pt', '')),
                door: trim((string) data_get($rawProperty, 'dt.locs.lous.lourb.loint.pu', '')),
                surface: $this->toNullableInt(data_get($rawProperty, 'debi.sfc')),
                year: $this->toNullableInt(data_get($rawProperty, 'debi.ant')),
                usage: trim((string) data_get($rawProperty, 'debi.luso', '')),
            );

            $properties[] = $dto->toArray();
        }

        return $properties;
    }

    public function get(string $endpoint, array $query = [], array $headers = []): Response
    {
        return $this->request('GET', $endpoint, [], $query, $headers);
    }

    public function requestJson(
        string $method,
        string $endpoint,
        array $payload = [],
        array $query = [],
        array $headers = []
    ): array {
        $data = $this->request($method, $endpoint, $payload, $query, $headers)->json();

        return is_array($data) ? $data : [];
    }

    public function request(
        string $method,
        string $endpoint,
        array $payload = [],
        array $query = [],
        array $headers = []
    ): Response {
        return $this->requestToBase(
            baseUrl: $this->baseUrl(),
            method: $method,
            endpoint: $endpoint,
            payload: $payload,
            query: $query,
            headers: $headers,
            asJson: true,
        );
    }

    private function requestToBase(
        string $baseUrl,
        string $method,
        string $endpoint,
        array $payload = [],
        array $query = [],
        array $headers = [],
        bool $asJson = true,
    ): Response {
        $normalizedMethod = strtoupper(trim($method));

        if (! in_array($normalizedMethod, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new InvalidArgumentException("Unsupported HTTP method [{$normalizedMethod}] for Catastro service.");
        }

        $request = $this->buildRequest($baseUrl, $headers, $asJson);

        $retryTimes = $this->retryTimes();

        if ($retryTimes > 0) {
            $request = $request->retry($retryTimes, $this->retrySleepMs());
        }

        if ($query !== []) {
            $request = $request->withQueryParameters($query);
        }

        try {
            $response = match ($normalizedMethod) {
                'GET' => $request->get($endpoint),
                'POST' => $request->post($endpoint, $payload),
                'PUT' => $request->put($endpoint, $payload),
                'PATCH' => $request->patch($endpoint, $payload),
                'DELETE' => $request->delete($endpoint),
            };

            return $response->throw();
        } catch (RequestException $exception) {
            $statusCode = $exception->response?->status();
            $responseBody = $exception->response?->body();

            throw new CatastroRequestException(
                message: sprintf('Catastro request failed with status [%s] on endpoint [%s].', (string) $statusCode, $endpoint),
                statusCode: $statusCode,
                responseBody: $responseBody,
                previous: $exception,
            );
        } catch (ConnectionException $exception) {
            throw new CatastroRequestException(
                message: sprintf('Catastro connection failed for endpoint [%s].', $endpoint),
                previous: $exception,
            );
        }
    }

    private function listify(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        return is_array($value) && array_is_list($value)
            ? $value
            : [$value];
    }

    private function buildRequest(string $baseUrl, array $headers = [], bool $asJson = true): PendingRequest
    {
        $request = $this->http
            ->baseUrl($baseUrl)
            ->withHeaders($headers)
            ->timeout($this->timeout())
            ->connectTimeout($this->connectTimeout());

        if ($asJson) {
            $request = $request->acceptJson()->asJson();
        }

        return $request;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.catastro.base_url', 'https://ovc.catastro.meh.es/OVCServWeb/OVCWcfCallejero/COVCCallejero.svc/json'), '/');
    }

    private function timeout(): float
    {
        return max(1.0, (float) config('services.catastro.timeout', 30));
    }

    private function connectTimeout(): float
    {
        return max(1.0, (float) config('services.catastro.connect_timeout', 10));
    }

    private function retryTimes(): int
    {
        return max(0, (int) config('services.catastro.retry_times', 2));
    }

    private function retrySleepMs(): int
    {
        return max(0, (int) config('services.catastro.retry_sleep_ms', 250));
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cleaned = preg_replace('/[^0-9]/', '', (string) $value);

        if (! is_string($cleaned) || $cleaned === '') {
            return null;
        }

        return (int) $cleaned;
    }
}
