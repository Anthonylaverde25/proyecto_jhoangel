<?php

declare(strict_types=1);

namespace App\Application\UseCases\Batches;

use App\Core\Entities\BatchEntity;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\IBatchTypeRepository;
use App\Core\Interfaces\IActivityRepository;
use App\Core\Interfaces\IFarmRepository;

final class GetOrCreateReserveBatchUseCase
{
    public function __construct(
        private readonly IBatchRepository $batchRepository,
        private readonly IBatchTypeRepository $batchTypeRepository,
        private readonly IActivityRepository $activityRepository,
        private readonly IFarmRepository $farmRepository
    ) {
    }

    public function __invoke(): BatchEntity
    {
        // 1. Check if the reserve batch already exists for the company
        $existing = $this->batchRepository->findSystemBatchByType('RESERVE');
        if ($existing !== null) {
            return $existing;
        }

        // 2. Resolve own farm for the company
        $ownFarms = $this->farmRepository->findOwnFarms();
        $ownFarm = !empty($ownFarms) ? reset($ownFarms) : null;
        $ownFarmId = $ownFarm?->getId();
        $ownFarmName = $ownFarm?->getName();

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
            farmId: $ownFarmId,
            observaciones: 'Lote interno del sistema para animales apartados, descartes reproductivos o reserva genética',
            isActive: true,
            createdAt: new \DateTimeImmutable(),
            farmName: $ownFarmName,
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

        // Add initial 0.0 weight history record
        $this->batchRepository->addWeight(
            $savedBatch->getId(),
            0.0,
            'INITIAL',
            new \DateTimeImmutable(),
            $activityId
        );

        return $savedBatch;
    }
}
