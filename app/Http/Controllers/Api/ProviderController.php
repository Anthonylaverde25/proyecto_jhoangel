<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\CreateProviderDTO;
use App\Application\UseCases\Providers\ProviderUseCases;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProviderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use App\Http\Requests\Providers\CreateProviderRequest;
use App\Http\Requests\Providers\UpdateProviderRolesRequest;

class ProviderController extends Controller
{
    public function __construct(
        private readonly ProviderUseCases $provider
    ) {
    }

    /**
     * Lista todos los proveedores.
     */
    public function index(Request $request): JsonResponse
    {
        $supplies = $request->query('supplies');
        $entities = ($this->provider->list)(in_array($supplies, ['livestock', 'items'], true) ? $supplies : null);
        
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

        $dto = CreateProviderDTO::fromArray($validated, app(ICompanyContext::class)->getCompanyId());
        $entity = ($this->provider->create)($dto);

        return response()->json(
            new ProviderResource($entity),
            201
        );
    }

    /**
     * Cambia qué vende el proveedor (hacienda, artículos o servicios).
     */
    public function updateRoles(UpdateProviderRolesRequest $request, int $id): JsonResponse
    {
        $entity = ($this->provider->updateRoles)(
            $id,
            $request->boolean('supplies_livestock'),
            $request->boolean('supplies_items'),
            (int) app(ICompanyContext::class)->getCompanyId()
        );

        return response()->json(new ProviderResource($entity));
    }
}
