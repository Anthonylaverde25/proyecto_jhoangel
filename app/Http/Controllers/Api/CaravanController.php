<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\RegisterCaravanDTO;
use App\Application\UseCases\Caravans\CaravanUseCases;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Http\Resources\CaravanResource;
use App\Http\Resources\CaravanMovementResource;
use App\Http\Resources\CaravanWeightResource;
use App\Application\DTOs\RecordCaravanWeightDTO;
use App\Application\DTOs\BulkRecordCaravanWeightDTO;
use App\Application\DTOs\WeanCaravanDTO;
use App\Application\DTOs\BulkWeanDTO;
use App\Core\Enums\AnimalSex;
use App\Http\Requests\Caravans\UpsertCaravanRequest;
use App\Http\Requests\Caravans\RecordCaravanWeightRequest;
use App\Http\Requests\Caravans\BulkStoreCaravanRequest;
use App\Http\Requests\Caravans\BulkRecordWeightRequest;
use App\Http\Requests\Caravans\WeanCaravanRequest;
use App\Http\Requests\Caravans\BulkWeanRequest;
use App\Application\UseCases\Caravans\GetCaravanPedigreeUseCase;
use App\Http\Resources\CaravanPedigreeResource;
use App\Application\DTOs\BulkTransferCaravansDTO;
use App\Http\Requests\Caravans\BulkTransferCaravansRequest;
use App\Application\UseCases\Caravans\BulkTransferCaravansUseCase;


class CaravanController extends Controller
{
    public function __construct(
        private readonly CaravanUseCases $caravan,
        private readonly \App\Application\UseCases\Caravans\BulkRegisterBirthUseCase $bulkRegisterBirth,
        private readonly \App\Application\UseCases\Caravans\RegisterGestationLossUseCase $registerGestationLoss,
        private readonly \App\Application\UseCases\Caravans\WeanCaravanUseCase $weanCaravan,
        private readonly \App\Application\UseCases\Caravans\BulkWeanCaravansUseCase $bulkWeanCaravans,
        private readonly \App\Application\UseCases\Caravans\RegisterGestationDiagnosisUseCase $registerGestationDiagnosis,
        private readonly \App\Application\UseCases\Caravans\BulkRegisterGestationDiagnosisUseCase $bulkRegisterGestationDiagnosis,
        private readonly GetCaravanPedigreeUseCase $getCaravanPedigree,
        private readonly BulkTransferCaravansUseCase $bulkTransferCaravans
    ) {
    }


    /**
     * Devuelve el análisis de pedigree 3G y consanguinidad de una caravana específica.
     */
    public function pedigree(int $id): JsonResponse
    {
        $data = ($this->getCaravanPedigree)($id);

        return response()->json(
            new CaravanPedigreeResource($data)
        );
    }

    /**
     * Lista todas las caravanas registradas según su alcance (own | external | all).
     */
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $scope = (string) $request->query('scope', 'own');
        $entities = ($this->caravan->list)($scope);
        
        return response()->json(
            CaravanResource::collection($entities)
        );
    }


    /**
     * Realiza un Upsert de una caravana.
     * Si la identificación existe, actualiza. Si no, crea.
     */
    public function upsert(UpsertCaravanRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dto = new RegisterCaravanDTO(
            identification: $validated['identification'],
            sex: isset($validated['sex']) ? AnimalSex::from($validated['sex']) : null,
            category: $validated['category'] ?? null,
            teeth: (int) $validated['teeth'],
            entryWeight: isset($validated['entry_weight']) ? (float) $validated['entry_weight'] : null,
            breed: $validated['breed'] ?? null,
            breedId: isset($validated['breed_id']) ? (int) $validated['breed_id'] : null,
            batchId: isset($validated['batch_id']) ? (int) $validated['batch_id'] : null,
            farmId: isset($validated['farm_id']) ? (int) $validated['farm_id'] : null,
            isEmpty: isset($validated['is_empty']) ? (bool) $validated['is_empty'] : null,
            gestationStage: $validated['gestation_stage'] ?? null,
            gestationMonths: isset($validated['gestation_months']) ? (float) $validated['gestation_months'] : null
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
    public function recordWeight(RecordCaravanWeightRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

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
    public function bulkStore(BulkStoreCaravanRequest $request): JsonResponse
    {
        $dtos = array_map(function ($data) {
            return new RegisterCaravanDTO(
                identification: $data['identification'],
                sex: isset($data['sex']) ? AnimalSex::from($data['sex']) : null,
                category: $data['category'] ?? null,
                teeth: (int) $data['teeth'],
                entryWeight: isset($data['entry_weight']) ? (float) $data['entry_weight'] : null,
                breed: $data['breed'] ?? null,
                breedId: isset($data['breed_id']) ? (int) $data['breed_id'] : null,
                batchId: isset($data['batch_id']) ? (int) $data['batch_id'] : null,
                farmId: isset($data['farm_id']) ? (int) $data['farm_id'] : null,
                isEmpty: isset($data['is_empty']) ? (bool) $data['is_empty'] : null,
                gestationStage: $data['gestation_stage'] ?? null,
                gestationMonths: isset($data['gestation_months']) ? (float) $data['gestation_months'] : null
            );
        }, $request->input('caravans'));

        ($this->caravan->bulk)($dtos);

        return response()->json(['message' => 'Caravanas procesadas correctamente'], 201);
    }

    /**
     * Registro masivo de pesajes para múltiples caravanas.
     */
    public function bulkRecordWeights(BulkRecordWeightRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $weightDtos = array_map(function ($data) {
            return new RecordCaravanWeightDTO(
                caravanId: $data['caravan_id'],
                weight: (float) $data['weight'],
                weighingDate: $data['weighing_date'],
                notes: $data['notes'] ?? null
            );
        }, $validated['weights']);

        $dto = new BulkRecordCaravanWeightDTO($weightDtos);

        ($this->caravan->bulkRecordWeights)($dto);

        return response()->json(['message' => 'Pesajes masivos registrados correctamente'], 201);
    }

    /**
     * Registro masivo de partos.
     */
    public function bulkBirth(\App\Http\Requests\Caravans\BulkBirthRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dtos = array_map(function ($data) {
            return \App\Application\DTOs\RegisterBirthDTO::fromArray($data);
        }, $validated['births']);

        $entities = ($this->bulkRegisterBirth)($dtos);

        return response()->json(
            CaravanResource::collection($entities),
            201
        );
    }

    /**
     * Registra una pérdida gestacional.
     */
    public function gestationLoss(\App\Http\Requests\Caravans\GestationLossRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        $entity = ($this->registerGestationLoss)(
            caravanId: $id,
            lossReasonId: (int) $validated['loss_reason_id'],
            lossNotes: $validated['loss_notes'] ?? null,
            lossDate: $validated['loss_date']
        );

        return response()->json(
            new CaravanResource($entity)
        );
    }

    /**
     * Registra el destete de una caravana.
     */
    public function wean(WeanCaravanRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();
        $dto = new WeanCaravanDTO(
            caravanId: $id,
            targetBatchId: (int) $validated['target_batch_id'],
            weaningDate: $validated['weaning_date'],
            weaningWeight: (float) $validated['weaning_weight'],
            newCategory: $validated['new_category'] ?? null,
            notes: $validated['notes'] ?? null
        );

        ($this->weanCaravan)($dto);

        return response()->json(null, 204);
    }

    /**
     * Registra el destete masivo de caravanas.
     */
    public function bulkWean(BulkWeanRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $dtos = array_map(
            fn($data) => WeanCaravanDTO::fromArray($data),
            $validated['weanings']
        );

        $dto = new BulkWeanDTO($dtos);
        ($this->bulkWeanCaravans)($dto);

        return response()->json(null, 204);
    }

    /**
     * Registra un diagnóstico gestacional (tacto / ecografía) para una caravana.
     */
    public function registerGestationDiagnosis(
        \App\Http\Requests\Caravans\GestationDiagnosisRequest $request,
        int $id
    ): JsonResponse {
        $validated = $request->validated();
        $validated['caravan_id'] = $id;

        $companyId = (int) $request->header('X-Company-ID');

        try {
            $dto = \App\Application\DTOs\RegisterGestationDiagnosisDTO::fromArray($validated);
            $entity = ($this->registerGestationDiagnosis)($dto, $companyId);

            return response()->json(new CaravanResource($entity));
        } catch (\App\Core\Exceptions\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Registers bulk gestation diagnosis for multiple caravans.
     */
    public function bulkGestationDiagnosis(
        \App\Http\Requests\Caravans\BulkGestationDiagnosisRequest $request
    ): JsonResponse {
        $validated = $request->validated();
        $companyId = (int) $request->header('X-Company-ID');

        $dtos = array_map(function ($data) {
            return \App\Application\DTOs\RegisterGestationDiagnosisDTO::fromArray($data);
        }, $validated['diagnoses']);

        try {
            $entities = ($this->bulkRegisterGestationDiagnosis)($dtos, $companyId);
            return response()->json(
                CaravanResource::collection($entities),
                201
            );
        } catch (\App\Core\Exceptions\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Lista las caravanas gestantes pertenecientes a un lote.
     */
    public function gestatingByBatch(int $id): JsonResponse
    {
        $entities = ($this->caravan->listGestatingByBatch)($id);
        
        return response()->json(
            CaravanResource::collection($entities)
        );
    }

    /**
     * Transfiere múltiples caravanas a un lote de destino o al Lote Reserva del Sistema.
     */
    public function bulkTransfer(BulkTransferCaravansRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $dto = BulkTransferCaravansDTO::fromArray($validated);

        try {
            $result = ($this->bulkTransferCaravans)($dto);
            return response()->json($result, 200);
        } catch (\App\Core\Exceptions\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}

