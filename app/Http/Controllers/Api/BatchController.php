<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\CreateBatchDTO;
use App\Application\UseCases\Batches\BatchUseCases;
use App\Http\Controllers\Controller;
use App\Http\Resources\BatchResource;
use App\Http\Resources\BatchWeightResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    public function __construct(
        private readonly BatchUseCases $batch
    ) {
    }

    /**
     * Lista el historial de pesos de un lote.
     */
    public function getWeightHistory(int $id): JsonResponse
    {
        $weights = ($this->batch->getWeights)($id);

        return response()->json(BatchWeightResource::collection($weights));
    }

    /**
     * Lista todos los lotes, opcionalmente filtrados por granja.
     */
    public function index(Request $request): JsonResponse
    {
        $farmId = $request->query('farm_id') ? (int) $request->query('farm_id') : null;
        $batchType = $request->query('batch_type') ? (string) $request->query('batch_type') : null;
        $entities = ($this->batch->list)($farmId, $batchType);
        
        return response()->json(
            BatchResource::collection($entities)
        );
    }

    public function store(Request $request): JsonResponse
    {
        \Illuminate\Support\Facades\Log::info('Store batch request data', [
            'headers' => $request->headers->all(),
            'data' => $request->all(),
        ]);

        try {
            $companyId = (int) $request->header('X-Company-ID');

            $validated = $request->validate([
                'name'          => 'required|string|max:255',
                'farm_id'       => 'nullable|integer|exists:farms,id',
                'activity_id'   => 'nullable|integer|exists:activities,id',
                'weight'        => 'nullable|numeric|min:0',
                'observaciones' => 'nullable|string',
                'batch_type_id' => [
                    'required',
                    'integer',
                    \Illuminate\Validation\Rule::exists('batch_types', 'id')->where('company_id', $companyId),
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Illuminate\Support\Facades\Log::error('Validation failed for batch creation', [
                'errors' => $e->errors(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Unexpected error in batch validation phase', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        try {
            $dto = CreateBatchDTO::fromArray($validated);
            $entity = ($this->batch->create)($dto);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Unexpected error in batch creation execution', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        return response()->json(
            new BatchResource($entity),
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        $entity = ($this->batch->find)($id);

        if (!$entity) {
            return response()->json(['message' => 'Lote no encontrado'], 404);
        }

        return response()->json(new BatchResource($entity));
    }

    /**
     * Cambia la actividad de un lote específico.
     */
    public function changeActivity(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'activity_id' => 'required|integer|exists:activities,id',
            'weight' => 'nullable|numeric|min:0',
        ]);

        $weight = isset($validated['weight']) ? (float) $validated['weight'] : null;

        $entity = ($this->batch->changeActivity)($id, (int) $validated['activity_id'], $weight);

        return response()->json(new BatchResource($entity));
    }
}
