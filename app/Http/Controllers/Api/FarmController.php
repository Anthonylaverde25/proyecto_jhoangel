<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\CreateFarmDTO;
use App\Application\UseCases\Farms\FarmUseCases;
use App\Http\Controllers\Controller;
use App\Http\Resources\FarmResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FarmController extends Controller
{
    public function __construct(
        private readonly FarmUseCases $farm
    ) {
    }

    /**
     * Lista todas las granjas, opcionalmente filtradas por proveedor.
     */
    public function index(Request $request): JsonResponse
    {
        $providerId = $request->query('provider_id') ? (int) $request->query('provider_id') : null;
        $entities = ($this->farm->list)($providerId);
        
        return response()->json(
            FarmResource::collection($entities)
        );
    }

    /**
     * Crea una nueva granja vinculada a un proveedor.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'renspa'      => 'required|string|unique:farms,renspa|max:255',
            'location'    => 'nullable|string|max:500',
            'provider_id' => 'required|integer|exists:providers,id',
        ]);

        $dto = CreateFarmDTO::fromArray($validated);
        $entity = ($this->farm->create)($dto);

        return response()->json(
            new FarmResource($entity),
            201
        );
    }
}
