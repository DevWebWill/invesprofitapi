<?php

namespace App\Services\CassandraAi;

use App\Exceptions\CassandraAiRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use LogicException;

class CassandraAiService
{
    public function __construct(
        private readonly Factory $http,
    ) {
    }

    public function get(string $endpoint, array $query = [], array $headers = []): Response
    {
        return $this->request('GET', $endpoint, [], $query, $headers);
    }

    public function post(string $endpoint, array $payload = [], array $query = [], array $headers = []): Response
    {
        return $this->request('POST', $endpoint, $payload, $query, $headers);
    }

    public function put(string $endpoint, array $payload = [], array $query = [], array $headers = []): Response
    {
        return $this->request('PUT', $endpoint, $payload, $query, $headers);
    }

    public function patch(string $endpoint, array $payload = [], array $query = [], array $headers = []): Response
    {
        return $this->request('PATCH', $endpoint, $payload, $query, $headers);
    }

    public function delete(string $endpoint, array $query = [], array $headers = []): Response
    {
        return $this->request('DELETE', $endpoint, [], $query, $headers);
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
        $normalizedMethod = strtoupper(trim($method));

        if (! in_array($normalizedMethod, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new InvalidArgumentException("Unsupported HTTP method [{$normalizedMethod}] for Cassandra AI service.");
        }

        $request = $this->http
            ->baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->withToken($this->token())
            ->withHeaders($headers)
            ->timeout($this->timeout())
            ->connectTimeout($this->connectTimeout());

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

            throw new CassandraAiRequestException(
                message: sprintf('Cassandra AI request failed with status [%s] on endpoint [%s].', (string) $statusCode, $endpoint),
                statusCode: $statusCode,
                responseBody: $responseBody,
                previous: $exception,
            );
        } catch (ConnectionException $exception) {
            throw new CassandraAiRequestException(
                message: sprintf('Cassandra AI connection failed for endpoint [%s].', $endpoint),
                previous: $exception,
            );
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.cassandra_ai.base_url', 'https://api.cassandra.ai'), '/');
    }

    private function token(): string
    {
        $token = trim((string) config('services.cassandra_ai.token', ''));

        if ($token === '') {
            throw new LogicException('Cassandra AI token is not configured. Set CASSANDRA_AI_TOKEN in environment variables.');
        }

        return $token;
    }

    private function timeout(): float
    {
        return max(1.0, (float) config('services.cassandra_ai.timeout', 30));
    }

    private function connectTimeout(): float
    {
        return max(1.0, (float) config('services.cassandra_ai.connect_timeout', 10));
    }

    private function retryTimes(): int
    {
        return max(0, (int) config('services.cassandra_ai.retry_times', 2));
    }

    private function retrySleepMs(): int
    {
        return max(0, (int) config('services.cassandra_ai.retry_sleep_ms', 250));
    }
}
