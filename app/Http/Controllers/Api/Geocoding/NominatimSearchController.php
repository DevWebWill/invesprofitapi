<?php

namespace App\Http\Controllers\Api\Geocoding;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class NominatimSearchController extends Controller
{
    private const FRESH_TTL_SECONDS = 240;
    private const STALE_TTL_SECONDS = 1800;
    private const DEFAULT_RETRY_AFTER_SECONDS = 20;
    private const MIN_RETRY_AFTER_SECONDS = 5;
    private const MAX_RETRY_AFTER_SECONDS = 120;
    private const DEFAULT_LIMIT = 8;
    private const MIN_LIMIT = 1;
    private const MAX_LIMIT = 10;

    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $acceptLanguage = (string) $request->query('accept-language', 'es');
        $params = [
            'q' => $query,
            'format' => 'jsonv2',
            'addressdetails' => (string) $request->query('addressdetails', '1'),
            'polygon_geojson' => (string) $request->query('polygon_geojson', '1'),
            'limit' => (string) $this->clampLimit($request->query('limit')),
            'accept-language' => $acceptLanguage,
        ];

        $countryCodes = trim((string) $request->query('countrycodes', ''));

        if ($countryCodes !== '') {
            $params['countrycodes'] = $countryCodes;
        }

        $baseUrl = rtrim((string) config('services.nominatim.base_url', 'https://nominatim.openstreetmap.org'), '/');
        $requestUrl = $baseUrl.'/search?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $freshCacheKey = $this->cacheKey('fresh', $requestUrl);
        $staleCacheKey = $this->cacheKey('stale', $requestUrl);

        if (Cache::has($freshCacheKey)) {
            return response()->json(Cache::get($freshCacheKey, []));
        }

        if ($this->isUpstreamRateLimited()) {
            return response()->json(Cache::get($staleCacheKey, []));
        }

        try {
            $payload = Http::acceptJson()
                ->withHeaders([
                    'Accept-Language' => $acceptLanguage,
                    'User-Agent' => (string) config('services.nominatim.user_agent', 'InvesProfit/1.0 (https://investprofit.local; dev@investprofit.app)'),
                    'Referer' => (string) config('services.nominatim.referer', 'https://investprofit.local'),
                ])
                ->timeout(10)
                ->get($baseUrl.'/search', $params)
                ->throw()
                ->json();

            $normalizedPayload = is_array($payload) ? array_values($payload) : [];

            Cache::put($freshCacheKey, $normalizedPayload, now()->addSeconds(self::FRESH_TTL_SECONDS));
            Cache::put($staleCacheKey, $normalizedPayload, now()->addSeconds(self::STALE_TTL_SECONDS));

            return response()->json($normalizedPayload);
        } catch (RequestException $exception) {
            if ($exception->response?->status() === 429) {
                $retryAfterSeconds = $this->normalizeRetryAfterSeconds($exception->response?->header('Retry-After'));

                Cache::put(
                    $this->rateLimitKey(),
                    now()->timestamp + $retryAfterSeconds,
                    now()->addSeconds($retryAfterSeconds)
                );
            }

            if (Cache::has($staleCacheKey)) {
                return response()->json(Cache::get($staleCacheKey, []));
            }

            report($exception);

            return response()->json([]);
        } catch (Throwable $exception) {
            if (Cache::has($staleCacheKey)) {
                return response()->json(Cache::get($staleCacheKey, []));
            }

            report($exception);

            return response()->json([]);
        }
    }

    private function cacheKey(string $prefix, string $requestUrl): string
    {
        return sprintf('nominatim:%s:%s', $prefix, sha1($requestUrl));
    }

    private function rateLimitKey(): string
    {
        return 'nominatim:rate_limited_until';
    }

    private function isUpstreamRateLimited(): bool
    {
        return (int) Cache::get($this->rateLimitKey(), 0) > now()->timestamp;
    }

    private function clampLimit(mixed $value): int
    {
        if (! is_numeric($value)) {
            return self::DEFAULT_LIMIT;
        }

        $limit = (int) round((float) $value);

        return min(self::MAX_LIMIT, max(self::MIN_LIMIT, $limit));
    }

    private function normalizeRetryAfterSeconds(mixed $value): int
    {
        if (! is_numeric($value)) {
            return self::DEFAULT_RETRY_AFTER_SECONDS;
        }

        $seconds = (int) round((float) $value);

        return min(self::MAX_RETRY_AFTER_SECONDS, max(self::MIN_RETRY_AFTER_SECONDS, $seconds));
    }
}
