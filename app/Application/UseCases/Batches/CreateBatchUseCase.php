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
            isConfined: $dto->isConfined,
            ageInMonths: $dto->ageInMonths,
            minWeight: $dto->minWeight,
            maxWeight: $dto->maxWeight
        );

        $savedEntity = $this->repository->save($entity);

        // El punto de apertura del lote. El peso va NULO cuando no se declaró ninguno:
        // un lote sin animales no tiene peso promedio, y escribir 0 afirmaría que los
        // animales no pesan nada, que es como la curva de un lote recién creado terminaba
        // arrancando desde el piso.
        $this->repository->addWeight(
            $savedEntity->getId(),
            $dto->weight,
            'INITIAL',
            new \DateTimeImmutable(),
            $activityId,
            totalWeight: 0.0,
            caravansCount: 0,
            weighedCount: 0,
            weightsAsOf: null
        );

        return $savedEntity;
    }
}
