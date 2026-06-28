<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class PropertyImportController extends Controller
{
    private const YIELD_GROSS_MAX = 12.0;

    private const RENT_SAMPLE_MIN_AREA_M2 = 20;
    private const RENT_SAMPLE_MAX_AREA_M2 = 400;
    private const RENT_PER_M2_MIN = 4.0;
    private const RENT_PER_M2_MAX = 80.0;
    private const RENT_COMPARABLE_MIN_FACTOR = 0.65;
    private const RENT_COMPARABLE_MAX_FACTOR = 1.35;
    private const RENT_CAP_PERCENTILE = 0.85;

    private function normalizeNumeric(mixed $value, int|float|null $default = null): int|float|null
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_numeric($value)) {
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }

        return $default;
    }

    private function normalizeString(mixed $value, string $default = ''): string
    {
        if (!is_string($value)) {
            return $default;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : $default;
    }

    private function buildPropertyExternalId(string $source, string $listingMode, string $rawExternalId): string
    {
        $sourcePart = trim(strtolower($source));
        if ($sourcePart === '') {
            $sourcePart = 'fotocasa';
        }

        $modePart = trim(strtolower($listingMode));
        $rawPart = trim($rawExternalId);

        if ($rawPart === '') {
            $rawPart = 'unknown';
        }

        $modePrefix = $sourcePart . '-' . $modePart . '-';
        if (str_starts_with($rawPart, $modePrefix)) {
            return $rawPart;
        }

        $sourcePrefix = $sourcePart . '-';
        if (str_starts_with($rawPart, $sourcePrefix)) {
            $rawPart = substr($rawPart, strlen($sourcePrefix));
        }

        return $modePrefix . $rawPart;
    }

    private function storeImages(Property $property, array $files, bool $replaceImages = true): array
    {
        $existingImages = $property->images ?? [];

        // Do not purge existing images when no files are provided.
        if (!$files) {
            return $existingImages;
        }

        if ($replaceImages && $existingImages) {
            Storage::disk('public')->delete($existingImages);
            $existingImages = [];
        }

        $source = $property->source ?: 'unknown';
        $citySlug = Str::slug($property->city ?: 'unknown');
        $baseDirectory = sprintf('properties/%s/%s/%s', $source, $citySlug, $property->external_id);

        if ($replaceImages) {
            $baseDirectory .= '/' . now()->format('YmdHis');
        }

        $storedPaths = [];

        foreach ($files as $index => $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg';
            $filename = sprintf('%02d.%s', $index + 1, $extension);
            $storedPaths[] = Storage::disk('public')->putFileAs($baseDirectory, $file, $filename);
        }

        return array_values(array_filter(array_merge($existingImages, $storedPaths)));
    }

    private function resolveMonthlyRent(
        string $city,
        string $propertyType,
        float $lat,
        float $lng,
        int $areaM2,
        ?int $monthlyRent,
        string $listingMode,
        ?string $district = null,
        ?string $neighborhood = null
    ): ?int {
        if (is_numeric($monthlyRent) && (int) $monthlyRent > 0) {
            return (int) $monthlyRent;
        }

        // Rent listings without rent cannot be estimated reliably from sale data.
        if ($listingMode === 'rent') {
            return null;
        }

        if ($areaM2 <= 0) {
            return null;
        }

        $district = $this->normalizeLocationToken($district);
        $neighborhood = $this->normalizeLocationToken($neighborhood);

        $locationStrategies = [];
        if ($district !== null || $neighborhood !== null) {
            if ($neighborhood !== null) {
                $locationStrategies[] = ['district' => $district, 'neighborhood' => $neighborhood];
            }

            if ($district !== null) {
                $locationStrategies[] = ['district' => $district, 'neighborhood' => null];
            }
        } else {
            $locationStrategies[] = ['district' => null, 'neighborhood' => null];
        }

        $radiusStepsKm = [0.7, 1.5];

        foreach ($locationStrategies as $strategy) {
            foreach ($radiusStepsKm as $radiusKm) {
                $rentPerM2 = $this->medianRentPerM2(
                    $city,
                    $propertyType,
                    $lat,
                    $lng,
                    $radiusKm,
                    $areaM2,
                    $strategy['district'],
                    $strategy['neighborhood']
                );

                if ($rentPerM2 !== null && $rentPerM2 > 0) {
                    $estimated = max(1, (int) round($rentPerM2 * $areaM2));
                    $localCap = $this->monthlyRentPercentileCap(
                        $city,
                        $propertyType,
                        $lat,
                        $lng,
                        $radiusKm,
                        $areaM2,
                        $strategy['district'],
                        $strategy['neighborhood']
                    );

                    if ($localCap !== null && $localCap > 0) {
                        return min($estimated, $localCap);
                    }

                    return $estimated;
                }

                $rentPerM2AnyType = $this->medianRentPerM2(
                    $city,
                    null,
                    $lat,
                    $lng,
                    $radiusKm,
                    $areaM2,
                    $strategy['district'],
                    $strategy['neighborhood']
                );

                if ($rentPerM2AnyType !== null && $rentPerM2AnyType > 0) {
                    $estimated = max(1, (int) round($rentPerM2AnyType * $areaM2));
                    $localCap = $this->monthlyRentPercentileCap(
                        $city,
                        null,
                        $lat,
                        $lng,
                        $radiusKm,
                        $areaM2,
                        $strategy['district'],
                        $strategy['neighborhood']
                    );

                    if ($localCap !== null && $localCap > 0) {
                        return min($estimated, $localCap);
                    }

                    return $estimated;
                }
            }
        }

        return null;
    }

    private function comparableAreaRange(int $areaM2): array
    {
        $minComparable = max(
            self::RENT_SAMPLE_MIN_AREA_M2,
            (int) floor($areaM2 * self::RENT_COMPARABLE_MIN_FACTOR)
        );
        $maxComparable = min(
            self::RENT_SAMPLE_MAX_AREA_M2,
            (int) ceil($areaM2 * self::RENT_COMPARABLE_MAX_FACTOR)
        );

        if ($minComparable > $maxComparable) {
            return [self::RENT_SAMPLE_MIN_AREA_M2, self::RENT_SAMPLE_MAX_AREA_M2];
        }

        return [$minComparable, $maxComparable];
    }

    private function normalizeLocationToken(?string $value): ?string
    {
        $normalized = strtolower(trim((string) $value));
        return $normalized !== '' ? $normalized : null;
    }

    private function resolveAddressContext(Property $property): array
    {
        $payload = is_array($property->source_payload) ? $property->source_payload : [];
        $address = $payload['listing']['address'] ?? [];

        return [
            'district' => $this->normalizeLocationToken($address['district'] ?? null),
            'neighborhood' => $this->normalizeLocationToken($address['neighborhood'] ?? ($address['upperLevel'] ?? null)),
        ];
    }

    private function matchesLocationContext(Property $property, ?string $district, ?string $neighborhood): bool
    {
        if ($district === null && $neighborhood === null) {
            return true;
        }

        $context = $this->resolveAddressContext($property);

        if ($neighborhood !== null) {
            return $context['neighborhood'] === $neighborhood;
        }

        return $district !== null && $context['district'] === $district;
    }

    private function medianRentPerM2(
        string $city,
        ?string $propertyType,
        float $lat,
        float $lng,
        float $radiusKm,
        int $areaM2,
        ?string $district,
        ?string $neighborhood
    ): ?float
    {
        $latDelta = $radiusKm / 111.32;
        $cosLat = cos(deg2rad($lat));
        $safeCosLat = abs($cosLat) < 0.2 ? 0.2 : abs($cosLat);
        $lngDelta = $radiusKm / (111.32 * $safeCosLat);
        [$minArea, $maxArea] = $this->comparableAreaRange($areaM2);

        $query = Property::query()
            ->where('city', $city)
            ->where('listing_mode', 'rent')
            ->whereNotNull('monthly_rent')
            ->where('monthly_rent', '>', 0)
            ->whereBetween('area_m2', [$minArea, $maxArea])
            ->whereBetween('lat', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('lng', [$lng - $lngDelta, $lng + $lngDelta])
            ->get(['monthly_rent', 'area_m2', 'source_payload', 'property_type']);

        $rentPerM2Samples = $query
            ->filter(function (Property $property) use ($propertyType, $district, $neighborhood) {
                if ($propertyType !== null && $property->property_type !== $propertyType) {
                    return false;
                }

                return $this->matchesLocationContext($property, $district, $neighborhood);
            })
            ->map(fn (Property $property) => (float) $property->monthly_rent / max(1.0, (float) $property->area_m2))
            ->filter(fn (float $value) => $value >= self::RENT_PER_M2_MIN && $value <= self::RENT_PER_M2_MAX)
            ->sort()
            ->values();

        $count = $rentPerM2Samples->count();

        if ($count < 3) {
            return null;
        }

        // Trim tails to reduce outlier impact in noisy imported datasets.
        $trimPerSide = (int) floor($count * 0.1);
        if ($trimPerSide > 0 && ($count - ($trimPerSide * 2)) >= 3) {
            $rentPerM2Samples = $rentPerM2Samples->slice($trimPerSide, $count - ($trimPerSide * 2))->values();
            $count = $rentPerM2Samples->count();
        }

        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (float) $rentPerM2Samples[$middle];
        }

        return ((float) $rentPerM2Samples[$middle - 1] + (float) $rentPerM2Samples[$middle]) / 2;
    }

    private function monthlyRentPercentileCap(
        string $city,
        ?string $propertyType,
        float $lat,
        float $lng,
        float $radiusKm,
        int $areaM2,
        ?string $district,
        ?string $neighborhood
    ): ?int
    {
        $latDelta = $radiusKm / 111.32;
        $cosLat = cos(deg2rad($lat));
        $safeCosLat = abs($cosLat) < 0.2 ? 0.2 : abs($cosLat);
        $lngDelta = $radiusKm / (111.32 * $safeCosLat);
        [$minArea, $maxArea] = $this->comparableAreaRange($areaM2);

        $query = Property::query()
            ->where('city', $city)
            ->where('listing_mode', 'rent')
            ->whereNotNull('monthly_rent')
            ->where('monthly_rent', '>', 0)
            ->whereBetween('area_m2', [$minArea, $maxArea])
            ->whereBetween('lat', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('lng', [$lng - $lngDelta, $lng + $lngDelta])
            ->get(['monthly_rent', 'source_payload', 'property_type']);

        $samples = $query
            ->filter(function (Property $property) use ($propertyType, $district, $neighborhood) {
                if ($propertyType !== null && $property->property_type !== $propertyType) {
                    return false;
                }

                return $this->matchesLocationContext($property, $district, $neighborhood);
            })
            ->pluck('monthly_rent')
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->sort()
            ->values();

        $count = $samples->count();
        if ($count < 4) {
            return null;
        }

        $index = (int) floor(($count - 1) * self::RENT_CAP_PERCENTILE);
        return max(1, (int) $samples[$index]);
    }

    private function cityAverageRentPerM2(string $city, string $propertyType): ?float
    {
        $baseQuery = Property::query()
            ->where('city', $city)
            ->where('listing_mode', 'rent')
            ->whereNotNull('monthly_rent')
            ->where('monthly_rent', '>', 0)
            ->whereBetween('area_m2', [self::RENT_SAMPLE_MIN_AREA_M2, self::RENT_SAMPLE_MAX_AREA_M2])
            ->whereRaw('(CAST(monthly_rent AS DECIMAL(10,2)) / area_m2) BETWEEN ? AND ?', [self::RENT_PER_M2_MIN, self::RENT_PER_M2_MAX]);

        $avg = (clone $baseQuery)
            ->where('property_type', $propertyType)
            ->selectRaw('AVG(CAST(monthly_rent AS DECIMAL(10,2)) / area_m2) as avg_rent_per_m2')
            ->value('avg_rent_per_m2');

        if ($avg === null) {
            $avg = (clone $baseQuery)
                ->selectRaw('AVG(CAST(monthly_rent AS DECIMAL(10,2)) / area_m2) as avg_rent_per_m2')
                ->value('avg_rent_per_m2');
        }

        if ($avg === null) {
            return null;
        }

        $value = (float) $avg;
        return $value > 0 ? $value : null;
    }

    private function resolveInvestmentMetrics(
        int $price,
        ?int $monthlyRent,
        string $listingMode,
        mixed $yieldGrossInput,
        mixed $yieldNetInput,
        mixed $roiAnnualInput,
        mixed $investmentScoreInput
    ): array {
        $gross = $this->normalizeNumeric($yieldGrossInput, null);
        $net = $this->normalizeNumeric($yieldNetInput, null);
        $roiAnnual = $this->normalizeNumeric($roiAnnualInput, null);
        $score = $this->normalizeNumeric($investmentScoreInput, null);

        if ($listingMode !== 'rent' && $price > 0 && is_numeric($monthlyRent) && (int) $monthlyRent > 0) {
            $calculatedGross = round((((int) $monthlyRent) * 12 / $price) * 100, 1);
            $calculatedGross = min($calculatedGross, self::YIELD_GROSS_MAX);
            $gross = is_numeric($gross) && (float) $gross > 0 ? (float) $gross : $calculatedGross;
            $gross = min($gross, self::YIELD_GROSS_MAX);
            $net = is_numeric($net) && (float) $net > 0 ? (float) $net : max(0, round(((float) $gross) - 1.1, 1));
            $roiAnnual = is_numeric($roiAnnual) && (float) $roiAnnual > 0 ? (float) $roiAnnual : round(((float) $net) / 1.10, 1);
            $score = is_numeric($score) && (int) $score > 0
                ? (int) $score
                : (int) max(0, min(100, round(40 + ((float) $gross * 8))));
        } elseif ($listingMode === 'rent') {
            $gross = 0.0;
            $net = 0.0;
            $roiAnnual = 0.0;
            $score = is_numeric($score) && (int) $score > 0 ? (int) $score : 0;
        }

        $gross = is_numeric($gross) ? max(0.0, min(self::YIELD_GROSS_MAX, (float) $gross)) : 0.0;
        $net = is_numeric($net) ? max(0.0, min(999.9, (float) $net)) : 0.0;
        $roiAnnual = is_numeric($roiAnnual) ? max(0.0, min(999.9, (float) $roiAnnual)) : 0.0;
        $score = is_numeric($score) ? max(0, min(100, (int) $score)) : 0;

        return [
            'monthly_rent' => is_numeric($monthlyRent) && (int) $monthlyRent > 0 ? (int) $monthlyRent : null,
            'yield_gross' => $gross,
            'yield_net' => $net,
            'roi_annual' => $roiAnnual,
            'investment_score' => $score,
        ];
    }

    public function normalizeRentPrices(): JsonResponse
    {
        $affected = Property::query()
            ->where('listing_mode', 'rent')
            ->where('price', '>', 0)
            ->update([
                'price' => 0,
                'yield_gross' => 0,
                'yield_net' => 0,
                'roi_annual' => 0,
                'investment_score' => 0,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Rent listings normalized successfully',
            'affected' => $affected,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'payload' => ['required', 'string'],
            'images' => ['sometimes', 'array'],
            'images.*' => ['file', 'image', 'max:10240'],
        ]);

        $payload = json_decode((string) $request->input('payload'), true);

        if (!is_array($payload)) {
            throw ValidationException::withMessages([
                'payload' => ['El payload debe ser un JSON válido.'],
            ]);
        }

        $validated = validator($payload, [
            'external_id' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'property_type' => ['required', 'string', 'max:255'],
            'listing_mode' => ['required', 'in:sale,rent'],
            'lat' => ['required', 'numeric'],
            'lng' => ['required', 'numeric'],
            'price' => ['required', 'integer', 'min:0'],
            'monthly_rent' => ['nullable', 'integer', 'min:0'],
            'bedrooms' => ['required', 'integer', 'min:0'],
            'bathrooms' => ['required', 'integer', 'min:0'],
            'area_m2' => ['required', 'integer', 'min:0'],
            'city' => ['required', 'string', 'max:255'],
            'region_slug' => ['required', 'string', 'max:255'],
            'yield_gross' => ['nullable', 'numeric'],
            'yield_net' => ['nullable', 'numeric'],
            'roi_annual' => ['nullable', 'numeric'],
            'investment_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'source' => ['nullable', 'string', 'max:100'],
            'detail_url' => ['nullable', 'string', 'max:2048'],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'description' => ['nullable', 'string'],
            'scraped_at' => ['nullable', 'date'],
            'source_payload' => ['nullable', 'array'],
            'replace_images' => ['nullable', 'boolean'],
        ])->validate();

        $lat = (float) $this->normalizeNumeric($validated['lat'], 0.0);
        $lng = (float) $this->normalizeNumeric($validated['lng'], 0.0);
        $rawPrice = (int) $this->normalizeNumeric($validated['price'], 0);
        $areaM2 = (int) ($validated['area_m2'] ?? 0);
        $city = $this->normalizeString($validated['city']);
        $propertyType = $this->normalizeString($validated['property_type'], 'activo');
        $listingMode = $validated['listing_mode'];
        $price = $listingMode === 'rent' ? 0 : $rawPrice;

        $incomingMonthlyRent = $this->normalizeNumeric($validated['monthly_rent'] ?? null, null);
        if ($listingMode === 'rent' && (!is_numeric($incomingMonthlyRent) || (int) $incomingMonthlyRent <= 0) && $rawPrice > 0) {
            // For rent feeds, price usually represents monthly rent amount.
            $incomingMonthlyRent = $rawPrice;
        }
        $resolvedMonthlyRent = $this->resolveMonthlyRent(
            $city,
            $propertyType,
            $lat,
            $lng,
            $areaM2,
            is_numeric($incomingMonthlyRent) ? (int) $incomingMonthlyRent : null,
            $listingMode,
            data_get($validated, 'source_payload.listing.address.district'),
            data_get($validated, 'source_payload.listing.address.neighborhood')
                ?: data_get($validated, 'source_payload.listing.address.upperLevel')
        );

        $metrics = $this->resolveInvestmentMetrics(
            $price,
            $resolvedMonthlyRent,
            $listingMode,
            $validated['yield_gross'] ?? null,
            $validated['yield_net'] ?? null,
            $validated['roi_annual'] ?? null,
            $validated['investment_score'] ?? null
        );

        $attributes = [
            'source' => $this->normalizeString($validated['source'] ?? 'fotocasa', 'fotocasa'),
            'detail_url' => $this->normalizeString($validated['detail_url'] ?? null) ?: null,
            'source_url' => $this->normalizeString($validated['source_url'] ?? null) ?: null,
            'title' => $this->normalizeString($validated['title']),
            'description' => $this->normalizeString($validated['description'] ?? null) ?: null,
            'property_type' => $propertyType,
            'listing_mode' => $listingMode,
            'lat' => $lat,
            'lng' => $lng,
            'price' => $price,
            'monthly_rent' => $metrics['monthly_rent'],
            'bedrooms' => (int) $validated['bedrooms'],
            'bathrooms' => (int) $validated['bathrooms'],
            'area_m2' => $areaM2,
            'city' => $city,
            'region_slug' => $this->normalizeString($validated['region_slug']),
            'yield_gross' => $metrics['yield_gross'],
            'yield_net' => $metrics['yield_net'],
            'roi_annual' => $metrics['roi_annual'],
            'investment_score' => $metrics['investment_score'],
            'source_payload' => $validated['source_payload'] ?? $payload,
            'scraped_at' => isset($validated['scraped_at']) ? Carbon::parse($validated['scraped_at']) : now(),
        ];

        $normalizedExternalId = $this->buildPropertyExternalId(
            $attributes['source'],
            $attributes['listing_mode'],
            (string) $validated['external_id']
        );

        $property = Property::query()->where('external_id', $normalizedExternalId)->first();
        $isNew = $property === null;

        if ($isNew) {
            $property = Property::query()->create(array_merge(['external_id' => $normalizedExternalId], $attributes));
        } else {
            $property->fill($attributes)->save();
        }

        $storedImages = $this->storeImages(
            $property,
            $request->file('images', []),
            (bool) ($validated['replace_images'] ?? true)
        );

        $property->forceFill([
            'images' => $storedImages,
        ])->save();

        $status = $isNew ? 201 : 200;
        $message = $isNew ? 'Property imported successfully' : 'Property updated successfully';

        return response()->json([
            'message' => $message,
            'data' => (new PropertyController())->show($property->external_id)->getData(true),
        ], $status);
    }
}
