<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\RegisterCaravanDTO;
use App\Application\UseCases\Caravans\CaravanUseCases;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Resources\CaravanResource;
use App\Http\Resources\CaravanMovementResource;
use App\Http\Resources\CaravanWeightResource;
use App\Application\DTOs\RecordCaravanWeightDTO;

class CaravanController extends Controller
{
    public function __construct(
        private readonly CaravanUseCases $caravan
    ) {
    }

    /**
     * Lista todas las caravanas registradas.
     */
    public function index(): JsonResponse
    {
        $entities = ($this->caravan->list)();
        
        return response()->json(
            CaravanResource::collection($entities)
        );
    }

    /**
     * Realiza un Upsert de una caravana.
     * Si la identificación existe, actualiza. Si no, crea.
     */
    public function upsert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identification' => 'required|string',
            'category'       => 'nullable|string',
            'teeth'          => 'required|integer|min:0|max:99',
            'entry_weight'   => 'nullable|numeric',
            'breed'          => 'nullable|string',
            'breed_id'       => 'nullable|integer|exists:breeds,id',
            'sex'            => 'nullable|string',
            'batch_id'       => 'nullable|integer|exists:batches,id',
            'farm_id'        => 'nullable|integer|exists:farms,id',
        ]);

        $dto = new RegisterCaravanDTO(
            identification: $validated['identification'],
            sex: $validated['sex'] ?? null,
            category: $validated['category'] ?? null,
            teeth: (int) $validated['teeth'],
            entryWeight: isset($validated['entry_weight']) ? (float) $validated['entry_weight'] : null,
            breed: $validated['breed'] ?? null,
            breedId: isset($validated['breed_id']) ? (int) $validated['breed_id'] : null,
            batchId: isset($validated['batch_id']) ? (int) $validated['batch_id'] : null,
            farmId: isset($validated['farm_id']) ? (int) $validated['farm_id'] : null
        );

        $result = ($this->caravan->upsert)($dto);

        return response()->json([
            'action' => $result->action,
            'id'     => $result->id,
        ], $result->action === 'created' ? 201 : 200);
    }

    /**
     * Registra un nuevo pesaje para una caravana específica.
     */
    public function recordWeight(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'weight'        => 'required|numeric|min:0',
            'weighing_date' => 'required|date',
            'notes'         => 'nullable|string',
        ]);

        $dto = new RecordCaravanWeightDTO(
            caravanId: $id,
            weight: (float) $validated['weight'],
            weighingDate: $validated['weighing_date'],
            notes: $validated['notes'] ?? null
        );

        ($this->caravan->recordWeight)($dto);

        return response()->json(['message' => 'Pesaje registrado correctamente'], 201);
    }

    /**
     * Lista el historial de pesajes de una caravana.
     */
    public function listWeights(int $id): JsonResponse
    {
        $weights = ($this->caravan->listWeights)($id);

        return response()->json(
            CaravanWeightResource::collection($weights)
        );
    }

    /**
     * Obtiene el historial de movimientos de una caravana.
     */
    public function movements(int $id): JsonResponse
    {
        $entities = $this->caravan->movements->execute($id);

        return response()->json(
            CaravanMovementResource::collection($entities)
        );
    }

    /**
     * Obtiene todos los movimientos recientes del sistema (auditoría global).
     */
    public function allMovements(): JsonResponse
    {
        $entities = $this->caravan->movements->execute();

        return response()->json(
            CaravanMovementResource::collection($entities)
        );
    }
    /**
     * Registro masivo de caravanas.
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $request->validate([
            'caravans' => 'required|array|min:1',
            'caravans.*.identification' => 'required|string',
            'caravans.*.category'       => 'nullable|string',
            'caravans.*.teeth'          => 'required|integer|min:0|max:99',
            'caravans.*.entry_weight'   => 'nullable|numeric',
            'caravans.*.breed'          => 'nullable|string',
            'caravans.*.breed_id'       => 'nullable|integer|exists:breeds,id',
            'caravans.*.sex'            => 'nullable|string',
            'caravans.*.batch_id'       => 'nullable|integer|exists:batches,id',
            'caravans.*.farm_id'        => 'nullable|integer|exists:farms,id',
        ]);

        $dtos = array_map(function ($data) {
            return new RegisterCaravanDTO(
                identification: $data['identification'],
                sex: $data['sex'] ?? null,
                category: $data['category'] ?? null,
                teeth: (int) $data['teeth'],
                entryWeight: isset($data['entry_weight']) ? (float) $data['entry_weight'] : null,
                breed: $data['breed'] ?? null,
                breedId: isset($data['breed_id']) ? (int) $data['breed_id'] : null,
                batchId: isset($data['batch_id']) ? (int) $data['batch_id'] : null,
                farmId: isset($data['farm_id']) ? (int) $data['farm_id'] : null
            );
        }, $request->input('caravans'));

        ($this->caravan->bulk)($dtos);

        return response()->json(['message' => 'Caravanas procesadas correctamente'], 201);
    }
}
