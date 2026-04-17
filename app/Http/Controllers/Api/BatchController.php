<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\CreateBatchDTO;
use App\Application\UseCases\Batches\BatchUseCases;
use App\Http\Controllers\Controller;
use App\Http\Resources\BatchResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    public function __construct(
        private readonly BatchUseCases $batch
    ) {
    }

    /**
     * Lista todos los lotes, opcionalmente filtrados por granja.
     */
    public function index(Request $request): JsonResponse
    {
        $farmId = $request->query('farm_id') ? (int) $request->query('farm_id') : null;
        $entities = ($this->batch->list)($farmId);
        
        return response()->json(
            BatchResource::collection($entities)
        );
    }

    /**
     * Crea un nuevo lote vinculado a una granja.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'farm_id'       => 'required|integer|exists:farms,id',
            'observaciones' => 'nullable|string',
        ]);

        $dto = CreateBatchDTO::fromArray($validated);
        $entity = ($this->batch->create)($dto);

        return response()->json(
            new BatchResource($entity),
            201
        );
    }
}
