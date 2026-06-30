<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatastroController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\Geocoding\NominatimSearchController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\RawPropertyImportController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::prefix('geocoding/nominatim')->group(function (): void {
    Route::get('search', NominatimSearchController::class);
});

Route::prefix('catastro')->group(function (): void {
    Route::get('provincias', [CatastroController::class, 'provincias']);
    Route::get('municipios', [CatastroController::class, 'municipios']);
    Route::get('tipos-via', [CatastroController::class, 'tiposVia']);
});

Route::prefix('location')->group(function (): void {
    Route::get('provinces', [LocationController::class, 'provinces']);
    Route::get('municipalities', [LocationController::class, 'municipalities']);
    Route::get('streets', [LocationController::class, 'streets']);
    Route::get('numbers', [LocationController::class, 'numbers']);
    Route::get('properties', [LocationController::class, 'properties']);
});

Route::get('properties', [PropertyController::class, 'index']);
Route::get('properties/{externalId}', [PropertyController::class, 'show']);
Route::get('markets/overview', [MarketController::class, 'overview']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('properties/import', 'App\\Http\\Controllers\\Api\\PropertyImportController@store');
    Route::post('properties/normalize-rent-prices', 'App\\Http\\Controllers\\Api\\PropertyImportController@normalizeRentPrices');
    Route::post('raw-properties/sale/import', [RawPropertyImportController::class, 'storeSale']);
    Route::post('raw-properties/rent/import', [RawPropertyImportController::class, 'storeRent']);
    Route::get('favorites', [FavoriteController::class, 'index']);
    Route::post('favorites', [FavoriteController::class, 'store']);
    Route::delete('favorites/{propertyExternalId}', [FavoriteController::class, 'destroy']);
});

Route::get('/test', function () {

    return response()->json(['ok' => true]);

});
