<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CatastroRequestException;
use App\Http\Controllers\Controller;
use App\Services\Catastro\CatastroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatastroController extends Controller
{
    public function __construct(
        private readonly CatastroService $catastroService,
    ) {
    }

    public function provincias(): JsonResponse
    {
        try {
            return response()->json($this->catastroService->obtenerProvincias());
        } catch (CatastroRequestException $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo consultar provincias en Catastro.',
            ], 503);
        }
    }

    public function municipios(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provincia' => ['required', 'string', 'max:120'],
        ]);

        try {
            return response()->json($this->catastroService->obtenerMunicipios($validated['provincia']));
        } catch (CatastroRequestException $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo consultar municipios en Catastro.',
            ], 503);
        }
    }

    public function tiposVia(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provincia' => ['required', 'string', 'max:120'],
            'municipio' => ['required', 'string', 'max:120'],
            'tipoVia' => ['nullable', 'string', 'max:120'],
            'nombreVia' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            return response()->json($this->catastroService->obtenerTiposVia(
                provincia: $validated['provincia'],
                municipio: $validated['municipio'],
                tipoVia: (string) ($validated['tipoVia'] ?? ''),
                nombreVia: (string) ($validated['nombreVia'] ?? ''),
            ));
        } catch (CatastroRequestException $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo consultar tipos de via en Catastro.',
            ], 503);
        }
    }
}
