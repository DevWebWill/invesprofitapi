<?php

namespace Tests\Unit;

use App\Exceptions\CassandraAiRequestException;
use App\Services\CassandraAi\CassandraAiService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class CassandraAiServiceTest extends TestCase
{
    public function test_it_sends_get_requests_with_the_expected_contract(): void
    {
        config()->set('services.cassandra_ai.base_url', 'https://api.cassandra.ai');
        config()->set('services.cassandra_ai.token', 'token-test');

        Http::fake([
            'https://api.cassandra.ai/v1/models*' => Http::response([
                'data' => [
                    ['id' => 'model-1'],
                ],
            ], 200),
        ]);

        $service = app(CassandraAiService::class);

        $response = $service->get('/v1/models', ['limit' => 10]);

        $this->assertTrue($response->successful());
        $this->assertSame('model-1', $response->json('data.0.id'));

        Http::assertSent(function (ClientRequest $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://api.cassandra.ai/v1/models?limit=10'
                && $request->hasHeader('Authorization', 'Bearer token-test')
                && $request->hasHeader('Accept', 'application/json');
        });
    }

    public function test_it_throws_a_domain_exception_for_upstream_http_errors(): void
    {
        config()->set('services.cassandra_ai.base_url', 'https://api.cassandra.ai');
        config()->set('services.cassandra_ai.token', 'token-test');

        Http::fake([
            'https://api.cassandra.ai/v1/chat/completions*' => Http::response([
                'error' => 'upstream failure',
            ], 500),
        ]);

        $service = app(CassandraAiService::class);

        try {
            $service->post('/v1/chat/completions', ['prompt' => 'hola']);
            $this->fail('Expected CassandraAiRequestException to be thrown.');
        } catch (CassandraAiRequestException $exception) {
            $this->assertSame(500, $exception->statusCode());
            $this->assertStringContainsString('upstream failure', (string) $exception->responseBody());
        }
    }

    public function test_it_throws_when_token_is_missing(): void
    {
        config()->set('services.cassandra_ai.base_url', 'https://api.cassandra.ai');
        config()->set('services.cassandra_ai.token', '');

        Http::preventStrayRequests();

        $service = app(CassandraAiService::class);

        $this->expectException(LogicException::class);

        $service->get('/v1/models');
    }
}
