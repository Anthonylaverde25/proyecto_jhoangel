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

final class UpsertCaravanUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly ICompanyContext $companyContext,
        private readonly CaravanTraceabilityService $traceabilityService,
        private readonly \App\Core\Services\BatchWeightService $batchWeightService
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

        $entity->updateDetails(
            $category,
            (int) $dto->teeth,
            $newWeight,
            null,
            $dto->breed,
            $dto->sex,
            null,
            $newBatchId,
            $dto->breedId
        );

        $this->caravanRepository->save($entity);

        // Synchronize Batch weights
        if ($newBatchId !== null && $newWeight !== null) {
            if ($oldBatchId === $newBatchId) {
                // Weight update within the same batch
                if (abs($oldWeight - $newWeight) > 0.001) {
                    $this->batchWeightService->updateBatchWeightAfterWeightChange($newBatchId, $oldWeight, $newWeight);
                }
            } else {
                // Moved from one batch to another
                if ($oldBatchId !== null) {
                    $this->batchWeightService->updateBatchWeightAfterRemoval($oldBatchId, $oldWeight);
                }
                $this->batchWeightService->updateBatchWeightAfterAddition($newBatchId, $newWeight);
            }
        } elseif ($oldBatchId !== null && $newBatchId === null) {
            // Removed from batch entirely
            $this->batchWeightService->updateBatchWeightAfterRemoval($oldBatchId, $oldWeight);
        }

        return new UpsertCaravanResultDTO('updated', $entity->getId());
    }

    private function handleTransfer(CaravanEntity $entity, int $newCompanyId, RegisterCaravanDTO $dto, ?AnimalCategory $category): UpsertCaravanResultDTO
    {
        $oldCompanyId = $entity->getCompanyId();
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

        $savedEntity = $this->caravanRepository->save($newEntity);

        // If it arrived with a batch and weight, sync it
        if ($newBatchId !== null && $newWeight !== null) {
            $this->batchWeightService->updateBatchWeightAfterAddition($newBatchId, $newWeight);
        }

        // Registrar trazabilidad de transferencia
        if ($oldCompanyId) {
            $this->traceabilityService->recordTransfer($savedEntity, $oldCompanyId, $newCompanyId);
        }

        return new UpsertCaravanResultDTO('updated', $savedEntity->getId());
    }

    private function handleNewArrival(CaravanNumber $identification, int $activeCompanyId, RegisterCaravanDTO $dto, ?AnimalCategory $category): UpsertCaravanResultDTO
    {
        if ($dto->sex === null || trim($dto->sex) === '') {
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

        $savedEntity = $this->caravanRepository->save($newEntity);

        // Recalculate Batch Weight
        if ($newBatchId !== null && $newWeight !== null) {
            $this->batchWeightService->updateBatchWeightAfterAddition($newBatchId, $newWeight);
        }

        // Registrar trazabilidad de llegada inicial
        $this->traceabilityService->recordInitialArrival($savedEntity, $activeCompanyId, $dto->farmId);

        return new UpsertCaravanResultDTO('created', $savedEntity->getId());
    }
}
