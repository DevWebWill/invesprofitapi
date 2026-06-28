<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PropertyController extends Controller
{
    private const RENT_SAMPLE_MIN_AREA_M2 = 20;
    private const RENT_SAMPLE_MAX_AREA_M2 = 400;
    private const RENT_PER_M2_MIN = 4.0;
    private const RENT_PER_M2_MAX = 80.0;

    private function medianNearbyRentPerM2(Property $property, ?string $propertyType = null): ?float
    {
        $lat = (float) $property->lat;
        $lng = (float) $property->lng;
        $radiusKm = 1.5;
        $latDelta = $radiusKm / 111.32;
        $cosLat = cos(deg2rad($lat));
        $safeCosLat = abs($cosLat) < 0.2 ? 0.2 : abs($cosLat);
        $lngDelta = $radiusKm / (111.32 * $safeCosLat);

        $query = Property::query()
            ->where('city', $property->city)
            ->where('listing_mode', 'rent')
            ->whereNotNull('monthly_rent')
            ->where('monthly_rent', '>', 0)
            ->whereBetween('area_m2', [self::RENT_SAMPLE_MIN_AREA_M2, self::RENT_SAMPLE_MAX_AREA_M2])
            ->whereBetween('lat', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('lng', [$lng - $lngDelta, $lng + $lngDelta]);

        if ($propertyType !== null) {
            $query->where('property_type', $propertyType);
        }

        $samples = $query
            ->selectRaw('CAST(monthly_rent AS DECIMAL(10,2)) / area_m2 as rent_per_m2')
            ->pluck('rent_per_m2')
            ->map(fn ($value) => (float) $value)
            ->filter(fn (float $value) => $value >= self::RENT_PER_M2_MIN && $value <= self::RENT_PER_M2_MAX)
            ->sort()
            ->values();

        $count = $samples->count();

        if ($count < 3) {
            return null;
        }

        $trimPerSide = (int) floor($count * 0.1);
        if ($trimPerSide > 0 && ($count - ($trimPerSide * 2)) >= 3) {
            $samples = $samples->slice($trimPerSide, $count - ($trimPerSide * 2))->values();
            $count = $samples->count();
        }

        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (float) $samples[$middle];
        }

        return ((float) $samples[$middle - 1] + (float) $samples[$middle]) / 2;
    }

    private function cityAverageRentPerM2(Property $property): ?float
    {
        $base = Property::query()
            ->where('city', $property->city)
            ->where('listing_mode', 'rent')
            ->whereNotNull('monthly_rent')
            ->where('monthly_rent', '>', 0)
            ->whereBetween('area_m2', [self::RENT_SAMPLE_MIN_AREA_M2, self::RENT_SAMPLE_MAX_AREA_M2])
            ->whereRaw('(CAST(monthly_rent AS DECIMAL(10,2)) / area_m2) BETWEEN ? AND ?', [self::RENT_PER_M2_MIN, self::RENT_PER_M2_MAX]);

        $avg = (clone $base)
            ->where('property_type', $property->property_type)
            ->selectRaw('AVG(CAST(monthly_rent AS DECIMAL(10,2)) / area_m2) as avg_rent_per_m2')
            ->value('avg_rent_per_m2');

        if ($avg === null) {
            $avg = (clone $base)
                ->selectRaw('AVG(CAST(monthly_rent AS DECIMAL(10,2)) / area_m2) as avg_rent_per_m2')
                ->value('avg_rent_per_m2');
        }

        if ($avg === null) {
            return null;
        }

        $value = (float) $avg;
        return $value > 0 ? $value : null;
    }

    private function resolveRentSuggestion(Property $property): array
    {
        $hasRent = is_numeric($property->monthly_rent) && (int) $property->monthly_rent > 0;
        if ($hasRent) {
            return [
                'rent_data_insufficient' => false,
                'suggested_monthly_rent' => null,
                'suggested_yield_gross' => null,
            ];
        }

        $price = is_numeric($property->price) ? (int) $property->price : 0;
        $areaM2 = is_numeric($property->area_m2) ? (int) $property->area_m2 : 0;

        if ($price <= 0 || $areaM2 <= 0) {
            return [
                'rent_data_insufficient' => true,
                'suggested_monthly_rent' => null,
                'suggested_yield_gross' => null,
            ];
        }

        $nearbyRentPerM2 = $this->medianNearbyRentPerM2($property, $property->property_type)
            ?? $this->medianNearbyRentPerM2($property, null);

        if ($nearbyRentPerM2 !== null) {
            $suggestedRent = max(1, (int) round($nearbyRentPerM2 * $areaM2));
            $suggestedYield = round(($suggestedRent * 12 / $price) * 100, 1);

            return [
                'rent_data_insufficient' => false,
                'suggested_monthly_rent' => $suggestedRent,
                'suggested_yield_gross' => $suggestedYield,
            ];
        }

        $cityRentPerM2 = $this->cityAverageRentPerM2($property);

        if ($cityRentPerM2 === null) {
            return [
                'rent_data_insufficient' => true,
                'suggested_monthly_rent' => null,
                'suggested_yield_gross' => null,
            ];
        }

        $suggestedRent = max(1, (int) round($cityRentPerM2 * $areaM2));
        $suggestedYield = round(($suggestedRent * 12 / $price) * 100, 1);

        return [
            'rent_data_insufficient' => true,
            'suggested_monthly_rent' => $suggestedRent,
            'suggested_yield_gross' => $suggestedYield,
        ];
    }

    /**
     * Build API-safe listing mode from legacy database fields.
     */
    private function resolveListingMode(Property $property): string
    {
        $hasSalePrice = is_numeric($property->price) && (int) $property->price > 0;
        $hasMonthlyRent = is_numeric($property->monthly_rent) && (int) $property->monthly_rent > 0;

        if ($property->listing_mode === 'rent' || (!$hasSalePrice && $hasMonthlyRent)) {
            return 'rent';
        }

        return 'sale';
    }

    /**
     * Serialize property for API contract.
     * Rent-only listings do not expose sale price.
     */
    private function transformProperty(Property $property): array
    {
        $mode = $this->resolveListingMode($property);
        $rentSuggestion = $this->resolveRentSuggestion($property);
        $images = collect($property->images ?? [])
            ->filter()
            ->map(function (string $path) {
                $normalized = trim($path);

                if ($normalized === '') {
                    return null;
                }

                if (Str::startsWith($normalized, ['http://', 'https://'])) {
                    $hostLower = Str::lower((string) parse_url($normalized, PHP_URL_HOST));
                    if ($hostLower !== '' && Str::contains($hostLower, 'fotocasa')) {
                        return null;
                    }
                    return $normalized;
                }

                $normalized = ltrim($normalized, '/');

                if (Str::startsWith($normalized, 'storage/')) {
                    return asset($normalized);
                }

                if (!Storage::disk('public')->exists($normalized)) {
                    return null;
                }

                return asset('storage/' . $normalized);
            })
            ->filter()
            ->values()
            ->all();

        return [
            'id' => $property->id,
            'external_id' => $property->external_id,
            'source' => $property->source,
            'detail_url' => $property->detail_url,
            'source_url' => $property->source_url,
            'title' => $property->title,
            'description' => $property->description,
            'property_type' => $property->property_type,
            'listing_mode' => $mode,
            'lat' => $property->lat,
            'lng' => $property->lng,
            'price' => $mode === 'rent' ? null : $property->price,
            'monthly_rent' => $property->monthly_rent,
            'bedrooms' => $property->bedrooms,
            'bathrooms' => $property->bathrooms,
            'area_m2' => $property->area_m2,
            'city' => $property->city,
            'region_slug' => $property->region_slug,
            'yield_gross' => $property->yield_gross,
            'yield_net' => $property->yield_net,
            'roi_annual' => $property->roi_annual,
            'investment_score' => $property->investment_score,
            'rent_data_insufficient' => $rentSuggestion['rent_data_insufficient'],
            'suggested_monthly_rent' => $rentSuggestion['suggested_monthly_rent'],
            'suggested_yield_gross' => $rentSuggestion['suggested_yield_gross'],
            'images' => $images,
            'source_payload' => $property->source_payload,
            'scraped_at' => $property->scraped_at,
            'created_at' => $property->created_at,
            'updated_at' => $property->updated_at,
        ];
    }

    /**
     * List all properties with optional filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Property::query();

        if ($request->filled('query')) {
            $search = trim((string) $request->input('query'));

            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('title', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('region_slug', 'like', "%{$search}%")
                    ->orWhere('external_id', 'like', "%{$search}%");
            });
        }

        if ($request->has('externalIds')) {
            $rawExternalIds = $request->input('externalIds');

            if (is_string($rawExternalIds)) {
                $externalIds = array_map('trim', explode(',', $rawExternalIds));
            } else {
                $externalIds = (array) $rawExternalIds;
            }

            $externalIds = array_values(array_filter($externalIds, fn ($id) => is_string($id) && trim($id) !== ''));

            if ($externalIds) {
                $query->whereIn('external_id', $externalIds);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // Price range filter
        if ($request->has('priceMin')) {
            $query->where('price', '>=', (int) $request->input('priceMin'));
        }
        if ($request->has('priceMax')) {
            $query->where('price', '<=', (int) $request->input('priceMax'));
        }

        // Yield range filter
        if ($request->has('yieldMin')) {
            $query->where('yield_gross', '>=', (float) $request->input('yieldMin'));
        }
        if ($request->has('yieldMax')) {
            $query->where('yield_gross', '<=', (float) $request->input('yieldMax'));
        }

        // Investment score filter
        if ($request->has('investmentScoreMin')) {
            $query->where('investment_score', '>=', (int) $request->input('investmentScoreMin'));
        }

        // Property types filter
        if ($request->has('propertyTypes')) {
            $types = (array) $request->input('propertyTypes');
            $query->whereIn('property_type', $types);
        }

        // Listing mode filter
        if ($request->has('listingModes')) {
            $modes = array_values(array_filter((array) $request->input('listingModes')));

            if ($modes) {
                $query->where(function ($subQuery) use ($modes) {
                    if (in_array('sale', $modes, true)) {
                        // Includes sale-only and mixed sale+rent rows.
                        $subQuery->orWhere('listing_mode', 'sale');
                    }

                    if (in_array('rent', $modes, true)) {
                        // Includes rent-only and mixed sale+rent rows.
                        $subQuery->orWhere('listing_mode', 'rent')
                            ->orWhereNotNull('monthly_rent');
                    }

                    if (in_array('both', $modes, true)) {
                        $subQuery->orWhere(function ($bothQuery) {
                            $bothQuery->where('listing_mode', 'sale')
                                ->whereNotNull('monthly_rent');
                        });
                    }
                });
            }
        }

        // City filter
        if ($request->has('city')) {
            $query->where('city', 'like', '%' . (string) $request->input('city') . '%');
        }

        // Region filter
        if ($request->has('regionSlug')) {
            $query->where('region_slug', (string) $request->input('regionSlug'));
        }

        $sortBy = (string) $request->input('sortBy', 'investment_score');
        $sortDir = strtolower((string) $request->input('sortDir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSortColumns = [
            'investment_score' => 'investment_score',
            'yield_gross' => 'yield_gross',
            'roi_annual' => 'roi_annual',
            'price' => 'price',
            'created_at' => 'created_at',
            'city' => 'city',
        ];
        $query->orderBy($allowedSortColumns[$sortBy] ?? 'investment_score', $sortDir);

        $isPaginatedRequest = $request->boolean('paginated') || $request->has('page') || $request->has('perPage');

        if ($isPaginatedRequest) {
            $perPage = min(max((int) $request->input('perPage', 24), 1), 100);
            $paginator = $query->paginate($perPage)->appends($request->query());
            $paginator->setCollection($paginator->getCollection()->map(fn (Property $property) => $this->transformProperty($property)));

            return response()->json($paginator);
        }

        $properties = $query->get()->map(fn (Property $property) => $this->transformProperty($property));

        return response()->json($properties);
    }

    /**
     * Get a single property by external_id.
     */
    public function show(string $externalId): JsonResponse
    {
        $property = Property::where('external_id', $externalId)->first();

        if (!$property) {
            return response()->json(['message' => 'Property not found'], 404);
        }

        return response()->json($this->transformProperty($property));
    }
}
