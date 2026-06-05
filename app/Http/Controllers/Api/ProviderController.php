<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\CreateProviderDTO;
use App\Application\UseCases\Providers\ProviderUseCases;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProviderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use App\Http\Requests\Providers\CreateProviderRequest;

class ProviderController extends Controller
{
    public function __construct(
        private readonly ProviderUseCases $provider
    ) {
    }

    /**
     * Lista todos los proveedores.
     */
    public function index(): JsonResponse
    {
        $entities = ($this->provider->list)();
        
        return response()->json(
            ProviderResource::collection($entities)
        );
    }

    /**
     * Obtiene un proveedor específico.
     */
    public function show(int $id): JsonResponse
    {
        $entity = ($this->provider->find)($id);
        
        return response()->json(
            new ProviderResource($entity)
        );
    }

    /**
     * Crea un nuevo proveedor.
     */
    public function store(CreateProviderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dto = CreateProviderDTO::fromArray($validated);
        $entity = ($this->provider->create)($dto);

        return response()->json(
            new ProviderResource($entity),
            201
        );
    }
}
