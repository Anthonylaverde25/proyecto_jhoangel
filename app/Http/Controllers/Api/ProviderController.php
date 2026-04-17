<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\CreateProviderDTO;
use App\Application\UseCases\Providers\ProviderUseCases;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProviderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * Crea un nuevo proveedor.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'commercial_name' => 'nullable|string|max:255',
            'cuit'            => 'required|string|max:20|unique:providers,cuit',
            'location'        => 'nullable|string|max:500',
            'email'           => 'nullable|email|max:255',
            'phone'           => 'nullable|string|max:50',
        ]);

        $dto = CreateProviderDTO::fromArray($validated);
        $entity = ($this->provider->create)($dto);

        return response()->json(
            new ProviderResource($entity),
            201
        );
    }
}
