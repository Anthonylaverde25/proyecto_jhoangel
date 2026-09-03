<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\RegisterCaravanDTO;
use App\Application\DTOs\UpsertCaravanResultDTO;
use App\Core\Entities\CaravanEntity;
use App\Core\Enums\AnimalCategory;
use App\Core\Interfaces\IAnimalCategoryRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Services\CaravanTraceabilityService;
use App\Core\ValueObjects\CaravanNumber;
use App\Core\ValueObjects\FemaleReproductiveDetails;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\GestationStage;

final class UpsertCaravanUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly ICompanyContext $companyContext,
        private readonly CaravanTraceabilityService $traceabilityService,
        private readonly \App\Core\Services\BatchWeightService $batchWeightService,
        private readonly RecordCaravanWeightUseCase $recordCaravanWeightUseCase,
        private readonly IAnimalCategoryRepository $animalCategoryRepository
    ) {
    }

    public function __invoke(RegisterCaravanDTO $dto): UpsertCaravanResultDTO
    {
        $identification = new CaravanNumber($dto->identification);
        $activeCompanyId = $this->companyContext->getCompanyId();
        
        // 1. Buscar en la compañía actual
        $existingEntity = $this->caravanRepository->findByIdentification($identification);
        $category = $dto->category !== null ? AnimalCategory::tryFrom($dto->category) : null;

        if ($existingEntity !== null) {
            return $this->handleUpdate($existingEntity, $dto, $category);
        }

        // 2. Si no existe localmente, buscar globalmente (Transferencia)
        $globalEntity = $this->caravanRepository->findByIdentificationGlobal($identification);
        if ($globalEntity !== null) {
            return $this->handleTransfer($globalEntity, $activeCompanyId, $dto, $category);
        }

        // 3. Si no existe en ningún lado, es una nueva llegada (Arrival)
        return $this->handleNewArrival($identification, $activeCompanyId, $dto, $category);
    }

    private function handleUpdate(CaravanEntity $entity, RegisterCaravanDTO $dto, ?AnimalCategory $category): UpsertCaravanResultDTO
    {
        $oldBatchId = $entity->getBatchId();
        $oldWeight = $entity->getEntryWeight() ?? 0.0;
        
        $newBatchId = $dto->batchId;
        $newWeight = $dto->entryWeight !== null ? (float) $dto->entryWeight : null;

        // Determine if we should update entry_weight (only if it's currently null)
        $preservedEntryWeight = $entity->getEntryWeight() ?? $newWeight;

        $entity->updateDetails(
            (int) $dto->teeth,
            $preservedEntryWeight,
            null,
            $dto->sex,
            null,
            $newBatchId,
            $dto->breedId,
            $dto->colorId,
            $dto->categoryId,
            $dto->subcategoryId
        );

        [$catId, $subId] = $this->resolveCategoryIds($dto, $category);
        if ($catId !== null) {
            $entity->setCategoryId($catId);
        }
        if ($subId !== null) {
            $entity->setSubcategoryId($subId);
        }

        if ($entity->getSex() === AnimalSex::FEMALE) {
            $currentDetails = $entity->getReproductiveDetails();
            $isEmpty = $dto->isEmpty !== null ? $dto->isEmpty : ($currentDetails?->isEmpty() ?? true);
            $arrivalCategory = $currentDetails?->getArrivalCategory() ?? ($category ?? AnimalCategory::VACA);
            
            $entity->recordFemaleDetails(new FemaleReproductiveDetails($isEmpty, $arrivalCategory));

            // Automatización Gestacional: Si pasa a vacía y tiene gestación activa, cerrarla como EXITOSA (Parto)
            if ($isEmpty && $entity->hasActiveGestation()) {
                $endDate = $dto->entryDate ?? date('Y-m-d');
                $entity->getActiveGestation()->closeGestation(
                    true,
                    $endDate,
                    'Closed via calving registration.'
                );
            }

            // Automatización Gestacional: Si está preñada y no tiene gestación activa, crear una
            if (!$isEmpty && !$entity->hasActiveGestation()) {
                $startDate = $dto->entryDate ?? date('Y-m-d');
                [$stage, $months] = $this->resolveGestationDetails($dto->gestationStage, $dto->gestationMonths);
                $entity->startNewGestation($startDate, $stage, $months);
            }
        }

        $this->caravanRepository->save($entity);

        // Record weight in caravan_weights
        $weightRecorded = false;
        if ($newWeight !== null) {
            ($this->recordCaravanWeightUseCase)(new \App\Application\DTOs\RecordCaravanWeightDTO(
                $entity->getId(),
                $newWeight,
                date('Y-m-d'),
                'Weight updated via upsert'
            ));
            $weightRecorded = true;
        }

        // Recalculate Batch weights
        if ($oldBatchId !== null && $oldBatchId !== $newBatchId) {
            $this->batchWeightService->recalculateBatchWeight($oldBatchId);
        }
        
        if ($newBatchId !== null && !$weightRecorded && $newBatchId !== $oldBatchId) {
            $this->batchWeightService->recalculateBatchWeight($newBatchId);
        }

        return new UpsertCaravanResultDTO('updated', $entity->getId());
    }

    private function handleTransfer(CaravanEntity $entity, int $newCompanyId, RegisterCaravanDTO $dto, ?AnimalCategory $category): UpsertCaravanResultDTO
    {
        $oldCompanyId = $entity->getCompanyId();
        $oldBatchId = $entity->getBatchId();
        $newWeight = $dto->entryWeight !== null ? (float) $dto->entryWeight : null;
        $newBatchId = $dto->batchId;

        // Actualizar datos y cambiar empresa
        $newEntity = new CaravanEntity(
            $entity->getId(),
            $entity->getIdentification(),
            (int) $dto->teeth,
            $newWeight,
            null,
            $dto->breedId,
            null,
            $dto->colorId,
            null,
            $dto->sex,
            null,
            null,
            $newBatchId,
            $newCompanyId,
            null,
            null,
            $entity->getReproductiveDetails(),
            $entity->getGestations()
        );

        [$catId, $subId] = $this->resolveCategoryIds($dto, $category);
        if ($catId !== null) {
            $newEntity->setCategoryId($catId);
        }
        if ($subId !== null) {
            $newEntity->setSubcategoryId($subId);
        }

        if ($newEntity->getSex() === AnimalSex::FEMALE) {
            $currentDetails = $entity->getReproductiveDetails();
            $isEmpty = $dto->isEmpty !== null ? $dto->isEmpty : ($currentDetails?->isEmpty() ?? true);
            $arrivalCategory = $currentDetails?->getArrivalCategory() ?? ($category ?? AnimalCategory::VACA);
            
            $newEntity->recordFemaleDetails(new FemaleReproductiveDetails($isEmpty, $arrivalCategory));

            // Automatización Gestacional: Si pasa a vacía y tiene gestación activa, cerrarla como EXITOSA
            if ($isEmpty && $newEntity->hasActiveGestation()) {
                $endDate = $dto->entryDate ?? date('Y-m-d');
                $newEntity->getActiveGestation()->closeGestation(
                    true,
                    $endDate,
                    'Closed via calving registration.'
                );
            }

            // Automatización Gestacional: Si está preñada y no tiene gestación activa, crear una
            if (!$isEmpty && !$newEntity->hasActiveGestation()) {
                $startDate = $dto->entryDate ?? date('Y-m-d');
                [$stage, $months] = $this->resolveGestationDetails($dto->gestationStage, $dto->gestationMonths);
                $newEntity->startNewGestation($startDate, $stage, $months);
            }
        }

        $savedEntity = $this->caravanRepository->save($newEntity);

        $weightRecorded = false;
        // Record weight in caravan_weights
        if ($newWeight !== null) {
            ($this->recordCaravanWeightUseCase)(new \App\Application\DTOs\RecordCaravanWeightDTO(
                $savedEntity->getId(),
                $newWeight,
                date('Y-m-d'),
                'Weight on transfer'
            ));
            $weightRecorded = true;
        }

        // Recalculate old Batch Weight (lost an animal)
        if ($oldBatchId !== null) {
            $this->batchWeightService->recalculateBatchWeight($oldBatchId);
        }

        // Recalculate new Batch Weight
        if ($newBatchId !== null && !$weightRecorded) {
            $this->batchWeightService->recalculateBatchWeight($newBatchId);
        }

        // Registrar trazabilidad de transferencia
        if ($oldCompanyId) {
            $this->traceabilityService->recordTransfer($savedEntity, $oldCompanyId, $newCompanyId);
        }

        return new UpsertCaravanResultDTO('updated', $savedEntity->getId());
    }

    private function handleNewArrival(CaravanNumber $identification, int $activeCompanyId, RegisterCaravanDTO $dto, ?AnimalCategory $category): UpsertCaravanResultDTO
    {
        if ($dto->sex === null) {
            throw new \App\Core\Exceptions\DomainException("El campo 'sexo' es obligatorio para registrar una nueva caravana.");
        }

        $newWeight = $dto->entryWeight !== null ? (float) $dto->entryWeight : null;
        $newBatchId = $dto->batchId;

        $newEntity = new CaravanEntity(
            null,
            $identification,
            (int) $dto->teeth,
            $newWeight,
            null,
            $dto->breedId,
            null,
            $dto->colorId,
            null,
            $dto->sex,
            null,
            null,
            $newBatchId,
            $activeCompanyId
        );

        [$catId, $subId] = $this->resolveCategoryIds($dto, $category);
        if ($catId !== null) {
            $newEntity->setCategoryId($catId);
        }
        if ($subId !== null) {
            $newEntity->setSubcategoryId($subId);
        }

        if ($newEntity->getSex() === AnimalSex::FEMALE) {
            $isEmpty = $dto->isEmpty !== null ? $dto->isEmpty : true;
            $arrivalCategory = $category ?? AnimalCategory::VACA;
            $newEntity->recordFemaleDetails(new FemaleReproductiveDetails($isEmpty, $arrivalCategory));

            // Automatización Gestacional: Si está preñada y no tiene gestación activa, crear una
            if (!$isEmpty && !$newEntity->hasActiveGestation()) {
                $startDate = $dto->entryDate ?? date('Y-m-d');
                [$stage, $months] = $this->resolveGestationDetails($dto->gestationStage, $dto->gestationMonths);
                $newEntity->startNewGestation($startDate, $stage, $months);
            }
        }

        $savedEntity = $this->caravanRepository->save($newEntity);

        $weightRecorded = false;
        // Record initial weight in caravan_weights
        if ($newWeight !== null) {
            ($this->recordCaravanWeightUseCase)(new \App\Application\DTOs\RecordCaravanWeightDTO(
                $savedEntity->getId(),
                $newWeight,
                date('Y-m-d'),
                'Initial weight on arrival'
            ));
            $weightRecorded = true;
        }

        // Recalculate Batch Weight
        if ($newBatchId !== null && !$weightRecorded) {
            $this->batchWeightService->recalculateBatchWeight($newBatchId);
        }

        // Registrar trazabilidad de llegada inicial
        $this->traceabilityService->recordInitialArrival($savedEntity, $activeCompanyId, $dto->farmId);

        return new UpsertCaravanResultDTO('created', $savedEntity->getId());
    }

    /**
     * Resolve category and subcategory IDs from DTO or legacy string/enum.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function resolveCategoryIds(RegisterCaravanDTO $dto, ?AnimalCategory $category): array
    {
        $catId = $dto->categoryId;
        $subId = $dto->subcategoryId;

        if ($catId !== null) {
            return [$catId, $subId];
        }

        $catCode = null;
        if ($category !== null) {
            $catCode = strtoupper($category->value);
        } elseif (!empty($dto->category)) {
            $catCode = strtoupper($dto->category);
        }

        if ($catCode !== null) {
            $codeMap = [
                'TERNERA' => 'TERNERO',
                'VACA_VACIA' => 'VACA',
                'VACA VACIA' => 'VACA',
            ];
            $searchCode = $codeMap[$catCode] ?? $catCode;
            $catEntity = $this->animalCategoryRepository->findByCode($searchCode);
            if ($catEntity) {
                $catId = $catEntity->getId();
            }
        }

        return [$catId, $subId];
    }

    /**
     * Resolve gestation stage and months bidirectionally.
     *
     * @return array{0: GestationStage, 1: float}
     */
    private function resolveGestationDetails(?string $stageStr, ?float $months): array
    {
        if ($months !== null) {
            $stage = GestationStage::fromMonths($months);
            return [$stage, $months];
        }

        if ($stageStr !== null) {
            $stage = GestationStage::from($stageStr);
            return [$stage, $stage->toDefaultMonths()];
        }

        // Fallback defaults
        return [GestationStage::HEAD, 3.0];
    }
}
