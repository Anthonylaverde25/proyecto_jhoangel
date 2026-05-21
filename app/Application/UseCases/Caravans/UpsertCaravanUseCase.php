<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\RegisterCaravanDTO;
use App\Application\DTOs\UpsertCaravanResultDTO;
use App\Core\Entities\CaravanEntity;
use App\Core\Enums\AnimalCategory;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Services\CaravanTraceabilityService;
use App\Core\ValueObjects\CaravanNumber;
use App\Core\ValueObjects\FemaleReproductiveDetails;
use App\Core\Enums\AnimalSex;

final class UpsertCaravanUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly ICompanyContext $companyContext,
        private readonly CaravanTraceabilityService $traceabilityService,
        private readonly \App\Core\Services\BatchWeightService $batchWeightService,
        private readonly RecordCaravanWeightUseCase $recordCaravanWeightUseCase
    ) {
    }

    public function __invoke(RegisterCaravanDTO $dto): UpsertCaravanResultDTO
    {
        $identification = new CaravanNumber($dto->identification);
        $activeCompanyId = $this->companyContext->getCompanyId();
        
        // 1. Buscar en la compañía actual
        $existingEntity = $this->caravanRepository->findByIdentification($identification);
        $category = $dto->category !== null ? AnimalCategory::from($dto->category) : null;

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
            $category,
            (int) $dto->teeth,
            $preservedEntryWeight,
            null,
            $dto->breed,
            $dto->sex,
            null,
            $newBatchId,
            $dto->breedId
        );

        if ($entity->getSex() === AnimalSex::FEMALE && $category !== null) {
            $currentDetails = $entity->getReproductiveDetails();
            $isEmpty = $dto->isEmpty !== null ? $dto->isEmpty : ($currentDetails?->isEmpty() ?? true);
            $arrivalCategory = $currentDetails?->getArrivalCategory() ?? $category;
            
            $entity->recordFemaleDetails(new FemaleReproductiveDetails($isEmpty, $arrivalCategory));

            // Automatización Gestacional: Si está preñada y no tiene gestación activa, crear una
            if (!$isEmpty && !$entity->hasActiveGestation()) {
                $startDate = $dto->entryDate ?? date('Y-m-d');
                $entity->startNewGestation($startDate);
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
            $category,
            (int) $dto->teeth,
            $newWeight,
            null,
            $dto->breed,
            $dto->breedId,
            $dto->sex,
            null,
            null,
            $newBatchId,
            $newCompanyId
        );

        if ($newEntity->getSex() === AnimalSex::FEMALE && $category !== null) {
            $currentDetails = $entity->getReproductiveDetails();
            $isEmpty = $dto->isEmpty !== null ? $dto->isEmpty : ($currentDetails?->isEmpty() ?? true);
            $arrivalCategory = $currentDetails?->getArrivalCategory() ?? $category;
            
            $newEntity->recordFemaleDetails(new FemaleReproductiveDetails($isEmpty, $arrivalCategory));

            // Automatización Gestacional: Si está preñada y no tiene gestación activa, crear una
            if (!$isEmpty && !$newEntity->hasActiveGestation()) {
                $startDate = $dto->entryDate ?? date('Y-m-d');
                $newEntity->startNewGestation($startDate);
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
            $category,
            (int) $dto->teeth,
            $newWeight,
            null,
            $dto->breed,
            $dto->breedId,
            $dto->sex,
            null,
            null,
            $newBatchId,
            $activeCompanyId
        );

        if ($newEntity->getSex() === AnimalSex::FEMALE && $category !== null) {
            $isEmpty = $dto->isEmpty !== null ? $dto->isEmpty : true;
            $newEntity->recordFemaleDetails(new FemaleReproductiveDetails($isEmpty, $category));

            // Automatización Gestacional: Si está preñada y no tiene gestación activa, crear una
            if (!$isEmpty && !$newEntity->hasActiveGestation()) {
                $startDate = $dto->entryDate ?? date('Y-m-d');
                $newEntity->startNewGestation($startDate);
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
}
