<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $externalIds = $user->favoriteProperties()
            ->orderBy('favorites.created_at', 'desc')
            ->pluck('properties.external_id')
            ->values();

        return response()->json([
            'data' => $externalIds,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'property_external_id' => ['required', 'string', 'exists:properties,external_id'],
        ]);

        $property = Property::query()
            ->where('external_id', $payload['property_external_id'])
            ->firstOrFail();

        $request->user()->favoriteProperties()->syncWithoutDetaching([$property->id]);

        return response()->json([
            'property_external_id' => $property->external_id,
            'message' => 'Favorite added',
        ], 201);
    }

    public function destroy(Request $request, string $propertyExternalId): JsonResponse
    {
        $property = Property::query()
            ->where('external_id', $propertyExternalId)
            ->first();

        if ($property) {
            $request->user()->favoriteProperties()->detach($property->id);
        }

        return response()->json(null, 204);
    }
}
