<?php

declare(strict_types=1);

namespace App\Application\UseCases\Batches;

use App\Application\DTOs\CreateBatchDTO;
use App\Core\Entities\BatchEntity;
use App\Core\Interfaces\IBatchRepository;

final class CreateBatchUseCase
{
    public function __construct(
        private readonly IBatchRepository $repository,
        private readonly \App\Core\Interfaces\IActivityRepository $activityRepository,
        private readonly \App\Core\Interfaces\IBatchTypeRepository $batchTypeRepository
    ) {
    }

    public function __invoke(CreateBatchDTO $dto): BatchEntity
    {
        $activityId = $dto->activityId;

        // Auto-assign internal activity if it's an internal batch type
        if ($dto->batchTypeId !== null) {
            $batchType = $this->batchTypeRepository->findById($dto->batchTypeId);
            if ($batchType !== null && in_array($batchType->getCode(), ['INTERNAL_CONSUMPTION', 'INTERNAL_DEATH', 'QUARANTINE'])) {
                $internalActivity = $this->activityRepository->findByCode('INTERNAL');
                if ($internalActivity !== null) {
                    $activityId = $internalActivity->getId();
                }
            }
        }

        $entity = new BatchEntity(
            id: null,
            name: $dto->name,
            farmId: $dto->farmId,
            observaciones: $dto->observaciones,
            isActive: true,
            activityId: $activityId,
            batchTypeId: $dto->batchTypeId,
            knowsToEat: $dto->knowsToEat,
            ageInMonths: $dto->ageInMonths,
            minWeight: $dto->minWeight,
            maxWeight: $dto->maxWeight
        );

        $savedEntity = $this->repository->save($entity);

        // Siempre se genera el registro de peso inicial al crear el lote.
        // Si no se provee peso, se asume 0.0 para el historial.
        $this->repository->addWeight(
            $savedEntity->getId(),
            $dto->weight ?? 0.0,
            'INITIAL',
            new \DateTimeImmutable(),
            $activityId
        );

        return $savedEntity;
    }
}
