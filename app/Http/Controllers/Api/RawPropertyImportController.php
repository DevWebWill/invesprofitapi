<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RawRentProperty;
use App\Models\RawSaleProperty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class RawPropertyImportController extends Controller
{
    public function storeSale(Request $request): JsonResponse
    {
        return $this->storeRaw($request, 'sale', RawSaleProperty::class);
    }

    public function storeRent(Request $request): JsonResponse
    {
        return $this->storeRaw($request, 'rent', RawRentProperty::class);
    }

    private function storeRaw(Request $request, string $listingMode, string $modelClass): JsonResponse
    {
        $payload = $this->extractPayload($request);
        $payloadListingMode = $this->stringOrNull($payload['listing_mode'] ?? null);

        if ($payloadListingMode !== null && $payloadListingMode !== $listingMode) {
            throw ValidationException::withMessages([
                'payload' => ["El payload no corresponde a un inmueble de {$listingMode}."],
            ]);
        }

        $externalId = $this->stringOrNull($payload['external_id'] ?? null);
        if ($externalId === null) {
            throw ValidationException::withMessages([
                'payload' => ['El payload raw debe incluir external_id.'],
            ]);
        }

        $source = $this->stringOrNull($payload['source'] ?? null) ?? 'fotocasa';

        /** @var Model $record */
        $record = $modelClass::query()->firstOrNew([
            'source' => $source,
            'external_id' => $externalId,
        ]);

        $record->fill([
            'source' => $source,
            'external_id' => $externalId,
            'listing_mode' => $listingMode,
            'title' => $this->stringOrNull($payload['title'] ?? null),
            'description' => $this->stringOrNull($payload['description'] ?? null),
            'property_type' => $this->stringOrNull($payload['property_type'] ?? null),
            'location' => $this->stringOrNull($payload['location'] ?? null),
            'city' => $this->stringOrNull($payload['city'] ?? null),
            'region_slug' => $this->stringOrNull($payload['region_slug'] ?? null),
            'lat' => $this->numericOrNull($payload['lat'] ?? null),
            'lng' => $this->numericOrNull($payload['lng'] ?? null),
            'price_text' => $this->stringOrNull($payload['price_text'] ?? null),
            'price_value' => $this->intOrNull($payload['price_value'] ?? null),
            'price' => $this->intOrNull($payload['price'] ?? null),
            'monthly_rent' => $this->intOrNull($payload['monthly_rent'] ?? null),
            'bedrooms' => $this->intOrNull($payload['bedrooms'] ?? null),
            'bathrooms' => $this->intOrNull($payload['bathrooms'] ?? null),
            'area_m2' => $this->intOrNull($payload['area_m2'] ?? null),
            'detail_url' => $this->stringOrNull($payload['detail_url'] ?? null),
            'source_url' => $this->stringOrNull($payload['source_url'] ?? null),
            'images' => is_array($payload['images'] ?? null) ? $payload['images'] : null,
            'downloaded_images' => is_array($payload['downloaded_images'] ?? null) ? $payload['downloaded_images'] : null,
            'source_payload' => is_array($payload['source_payload'] ?? null) ? $payload['source_payload'] : null,
            'raw_payload' => $payload,
            'scraped_at' => $this->timestampOrNull($payload['scraped_at'] ?? null),
        ]);

        $record->save();

        $storedImages = $this->storeImages($record, $request->file('images', []));
        if ($storedImages) {
            $record->forceFill(['downloaded_images' => $storedImages])->save();
        }

        return response()->json([
            'message' => $record->wasRecentlyCreated ? 'Raw property imported successfully' : 'Raw property updated successfully',
            'data' => [
                'id' => $record->getAttribute('id'),
                'source' => $record->getAttribute('source'),
                'external_id' => $record->getAttribute('external_id'),
                'listing_mode' => $record->getAttribute('listing_mode'),
                'stored_images' => $record->getAttribute('downloaded_images') ?? [],
            ],
        ], $record->wasRecentlyCreated ? 201 : 200);
    }

    private function storeImages(Model $record, array $files): array
    {
        if (!$files) {
            return [];
        }

        $source = $record->getAttribute('source') ?: 'unknown';
        $citySlug = Str::slug($record->getAttribute('city') ?: 'unknown');
        $externalId = $record->getAttribute('external_id');
        $dir = sprintf('raw-properties/%s/%s/%s', $source, $citySlug, $externalId);

        $stored = [];
        foreach ($files as $index => $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }
            $ext = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg';
            $filename = sprintf('%02d.%s', $index + 1, $ext);
            $path = Storage::disk('public')->putFileAs($dir, $file, $filename);
            if ($path) {
                $stored[] = $path;
            }
        }

        return $stored;
    }

    private function extractPayload(Request $request): array
    {
        $rawPayload = $request->input('payload', $request->json('payload'));

        if (is_string($rawPayload)) {
            $decoded = json_decode($rawPayload, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                throw ValidationException::withMessages([
                    'payload' => ['El campo payload debe contener JSON válido.'],
                ]);
            }

            return $decoded;
        }

        if (is_array($rawPayload)) {
            return $rawPayload;
        }

        throw ValidationException::withMessages([
            'payload' => ['El campo payload es obligatorio.'],
        ]);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function numericOrNull(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return str_contains((string) $value, '.') ? (float) $value : (int) $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        $numeric = $this->numericOrNull($value);
        return is_numeric($numeric) ? (int) $numeric : null;
    }

    private function timestampOrNull(mixed $value): ?Carbon
    {
        $stringValue = $this->stringOrNull($value);
        return $stringValue !== null ? Carbon::parse($stringValue) : null;
    }
}
