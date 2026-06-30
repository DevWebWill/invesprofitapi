<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CatastroRequestException;
use App\Http\Controllers\Controller;
use App\Services\Catastro\CatastroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function __construct(
        private readonly CatastroService $catastroService,
    ) {
    }

    public function provinces(): JsonResponse
    {
        try {
            return response()->json($this->catastroService->obtenerProvincias());
        } catch (CatastroRequestException $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudieron obtener las provincias.',
            ], 503);
        }
    }

    public function municipalities(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'province' => ['required', 'string', 'max:120'],
        ]);

        try {
            return response()->json($this->catastroService->obtenerMunicipios($validated['province']));
        } catch (CatastroRequestException $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudieron obtener los municipios.',
            ], 503);
        }
    }

    public function streets(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'province' => ['required', 'string', 'max:120'],
            'municipality' => ['required', 'string', 'max:120'],
            'search' => ['required', 'string', 'min:3', 'max:120'],
        ]);

        try {
            return response()->json($this->catastroService->obtenerCallejero(
                provincia: $validated['province'],
                municipio: $validated['municipality'],
                search: $validated['search'],
            ));
        } catch (CatastroRequestException $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudieron obtener las vias.',
            ], 503);
        }
    }

    public function numbers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'province' => ['required', 'string', 'max:120'],
            'municipality' => ['required', 'string', 'max:120'],
            'street_type' => ['required', 'string', 'max:60'],
            'street' => ['required', 'string', 'max:160'],
            'number' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            return response()->json($this->catastroService->obtenerNumerero(
                provincia: $validated['province'],
                municipio: $validated['municipality'],
                tipoVia: $validated['street_type'],
                nomVia: $validated['street'],
                numero: (string) ($validated['number'] ?? ''),
            ));
        } catch (CatastroRequestException $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudieron obtener los numeros de via.',
            ], 503);
        }
    }

    public function properties(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parcel_reference' => ['required', 'string', 'max:30'],
            'province' => ['required', 'string', 'max:120'],
            'municipality' => ['required', 'string', 'max:120'],
        ]);

        try {
            return response()->json($this->catastroService->consultarPropiedadesParcela(
                provincia: $validated['province'],
                municipio: $validated['municipality'],
                parcelReference: $validated['parcel_reference'],
            ));
        } catch (CatastroRequestException $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo obtener el listado de inmuebles.',
            ], 503);
        }
    }
}
