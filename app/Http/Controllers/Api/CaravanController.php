<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Caravans\UpdateBreedingEligibilityDTO;
use App\Http\Requests\Caravans\UpdateBreedingEligibilityRequest;
use App\Application\DTOs\RegisterCaravanDTO;
use App\Application\UseCases\Caravans\CaravanUseCases;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Http\Resources\CaravanResource;
use App\Http\Resources\CaravanMovementResource;
use App\Http\Resources\CaravanWeightResource;
use App\Application\DTOs\RecordCaravanWeightDTO;
use App\Application\DTOs\BulkRecordCaravanWeightDTO;
use App\Core\Enums\AnimalSex;
use App\Http\Requests\Caravans\UpsertCaravanRequest;
use App\Http\Requests\Caravans\RecordCaravanWeightRequest;
use App\Http\Requests\Caravans\BulkStoreCaravanRequest;
use App\Http\Requests\Caravans\BulkRecordWeightRequest;
use App\Application\UseCases\Caravans\GetCaravanPedigreeUseCase;
use App\Http\Resources\CaravanPedigreeResource;
use App\Application\DTOs\BulkTransferCaravansDTO;
use App\Http\Requests\Caravans\BulkTransferCaravansRequest;
use App\Application\UseCases\Caravans\BulkTransferCaravansUseCase;
use App\Application\UseCases\Caravans\PaginateCaravansSummaryUseCase;
use App\Http\Resources\CaravanSummaryResource;
use App\Core\ValueObjects\CaravanSummaryCriteria;


class CaravanController extends Controller
{
    public function __construct(
        private readonly CaravanUseCases $caravan,
        private readonly \App\Application\UseCases\Caravans\BulkRegisterBirthUseCase $bulkRegisterBirth,
        private readonly \App\Application\UseCases\Caravans\RegisterGestationLossUseCase $registerGestationLoss,
        private readonly \App\Application\UseCases\Caravans\RegisterGestationDiagnosisUseCase $registerGestationDiagnosis,
        private readonly \App\Application\UseCases\Caravans\BulkRegisterGestationDiagnosisUseCase $bulkRegisterGestationDiagnosis,
        private readonly GetCaravanPedigreeUseCase $getCaravanPedigree,
        private readonly BulkTransferCaravansUseCase $bulkTransferCaravans,
        private readonly PaginateCaravansSummaryUseCase $paginateCaravansSummary
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
     * One animal's record by id. 404 when it does not exist or belongs to another company.
     */
    public function show(int $id): JsonResponse
    {
        $entity = ($this->caravan->show)($id);

        if ($entity === null) {
            return response()->json(['message' => 'Caravana no encontrada.'], 404);
        }

        return response()->json(['data' => new CaravanResource($entity)]);
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
     * Lista paginada y ligera de caravanas para vistas de inventario y tablas de alto rendimiento.
     */
    public function summary(\Illuminate\Http\Request $request): JsonResponse
    {
        $batchId = $request->query('batch_id');
        $search = $request->query('search');
        $sex = $request->query('sex');
        $sort = $request->query('sort');
        $categoryId = (int) $request->query('category_id', 0);

        $result = ($this->paginateCaravansSummary)(new CaravanSummaryCriteria(
            scope: (string) $request->query('scope', 'own'),
            batchId: is_string($batchId) && $batchId !== '' ? $batchId : null,
            search: $search ? (string) $search : null,
            sex: $sex ? (string) $sex : null,
            sort: $sort ? (string) $sort : null,
            page: (int) $request->query('page', 1),
            perPage: (int) $request->query('per_page', 25),
            categoryId: $categoryId > 0 ? $categoryId : null,
        ));

        $count = count($result->items);
        $from = $count > 0 ? ($result->page - 1) * $result->perPage + 1 : null;

        return response()->json([
            'data' => CaravanSummaryResource::collection($result->items)->resolve($request),
            'meta' => [
                'current_page' => $result->page,
                'last_page'    => $result->lastPage(),
                'per_page'     => $result->perPage,
                'total'        => $result->total,
                'from'         => $from,
                'to'           => $from !== null ? $from + $count - 1 : null,
            ],
        ]);
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
            gestationMonths: isset($validated['gestation_months']) ? (float) $validated['gestation_months'] : null,
            categoryId: isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            subcategoryId: isset($validated['subcategory_id']) ? (int) $validated['subcategory_id'] : null,
            entryDate: $validated['entry_date'] ?? null
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

        $dto = RecordCaravanWeightDTO::fromArray(['caravan_id' => $id] + $validated);

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
                gestationMonths: isset($data['gestation_months']) ? (float) $data['gestation_months'] : null,
                categoryId: isset($data['category_id']) ? (int) $data['category_id'] : null,
                subcategoryId: isset($data['subcategory_id']) ? (int) $data['subcategory_id'] : null,
                entryDate: isset($data['entry_date']) ? (string) $data['entry_date'] : null
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

        $weightDtos = array_map(
            static fn (array $data): RecordCaravanWeightDTO => RecordCaravanWeightDTO::fromArray($data),
            $validated['weights']
        );

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
            lossDate: $validated['loss_date'],
            actionUserId: $request->user()?->id
        );

        return response()->json(
            new CaravanResource($entity)
        );
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
        $validated['user_id'] = $request->user()?->id;

        $companyId = (int) $request->header('X-Company-ID');

        try {
            $dto = \App\Application\DTOs\RegisterGestationDiagnosisDTO::fromArray($validated);
            $entity = ($this->registerGestationDiagnosis)($dto, $companyId);

            return response()->json(new CaravanResource($entity));
        } catch (\App\Core\Exceptions\MotherCalfSeparationException $e) {
            // Rendered with its code and field, so the screen asks for the kind of weaning.
            throw $e;
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

        $userId = $request->user()?->id;
        $dtos = array_map(function ($data) use ($userId) {
            return \App\Application\DTOs\RegisterGestationDiagnosisDTO::fromArray($data + ['user_id' => $userId]);
        }, $validated['diagnoses']);

        try {
            $entities = ($this->bulkRegisterGestationDiagnosis)($dtos, $companyId);
            return response()->json(
                CaravanResource::collection($entities),
                201
            );
        } catch (\App\Core\Exceptions\MotherCalfSeparationException $e) {
            // Rendered with its code and field, so the screen asks for the kind of weaning.
            throw $e;
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
        } catch (\App\Core\Exceptions\MotherCalfSeparationException $e) {
            // Rendered with its code and field, so the screen asks for the kind of weaning.
            throw $e;
        } catch (\App\Core\Exceptions\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * The owner marks or unmarks animals as fit for breeding (one or many).
     */
    public function bulkBreedingEligibility(UpdateBreedingEligibilityRequest $request): JsonResponse
    {
        $dto = UpdateBreedingEligibilityDTO::fromArray(
            $request->validated(),
            (int) $request->header('X-Company-ID'),
            $request->user()?->id !== null ? (int) $request->user()->id : null
        );

        try {
            return response()->json(($this->caravan->updateBreedingEligibility)($dto), 200);
        } catch (\App\Core\Exceptions\MotherCalfSeparationException $e) {
            // Rendered with its code and field, so the screen asks for the kind of weaning.
            throw $e;
        } catch (\App\Core\Exceptions\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
