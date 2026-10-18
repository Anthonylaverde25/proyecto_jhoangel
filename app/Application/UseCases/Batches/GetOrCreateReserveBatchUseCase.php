<?php

declare(strict_types=1);

namespace App\Application\UseCases\Batches;

use App\Core\Entities\BatchEntity;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\IBatchTypeRepository;
use App\Core\Interfaces\IActivityRepository;

final class GetOrCreateReserveBatchUseCase
{
    public function __construct(
        private readonly IBatchRepository $batchRepository,
        private readonly IBatchTypeRepository $batchTypeRepository,
        private readonly IActivityRepository $activityRepository
    ) {
    }

    public function __invoke(): BatchEntity
    {
        // 1. Check if the reserve batch already exists for the company
        $existing = $this->batchRepository->findSystemBatchByType('RESERVE');
        if ($existing !== null) {
            return $existing;
        }

        // 2. An own batch has no farm: the company is its own farm (D21).

        // 3. Resolve RESERVE batch type
        $batchType = $this->batchTypeRepository->findByCode('RESERVE');
        $batchTypeId = $batchType?->getId();

        // 4. Resolve internal activity if available
        $internalActivity = $this->activityRepository->findByCode('INTERNAL');
        $activityId = $internalActivity?->getId();

        // 5. Create the system reserve batch
        $batchEntity = new BatchEntity(
            id: null,
            name: 'Lote Reserva | Animales Apartados',
            farmId: null,
            observaciones: 'Lote interno del sistema para animales apartados, descartes reproductivos o reserva genética',
            isActive: true,
            createdAt: new \DateTimeImmutable(),
            farmName: null,
            providerId: null,
            providerName: null,
            activityId: $activityId,
            activityName: $internalActivity?->getName(),
            currentWeight: 0.0,
            caravansCount: 0,
            batchTypeId: $batchTypeId,
            batchTypeName: $batchType?->getName(),
            batchTypeCode: 'RESERVE',
            isSystem: true
        );


        $savedBatch = $this->batchRepository->save($batchEntity);

        // Opening point of the reserve batch. The average goes NULL rather than 0.0: the
        // batch holds no animals yet, and an average over an empty set is undefined.
        $this->batchRepository->addWeight(
            $savedBatch->getId(),
            null,
            'INITIAL',
            new \DateTimeImmutable(),
            $activityId,
            totalWeight: 0.0,
            caravansCount: 0,
            weighedCount: 0,
            weightsAsOf: null
        );

        return $savedBatch;
    }
}
