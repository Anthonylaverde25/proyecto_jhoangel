<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Entities\BatchEntity;
use App\Core\Exceptions\RegisterDeathException;
use App\Core\Interfaces\IActivityRepository;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\IBatchTypeRepository;

/**
 * The death batch a dead animal goes to: the only one the company has, the one chosen when it has
 * several, or a new "Lote de Muertes" when it has none.
 */
final class DeathBatchResolver
{
    public const TYPE = 'INTERNAL_DEATH';

    public function __construct(
        private readonly IBatchRepository $batchRepository,
        private readonly IBatchTypeRepository $batchTypeRepository,
        private readonly IActivityRepository $activityRepository
    ) {
    }

    /**
     * @return list<BatchEntity> the active death batches of the company
     */
    public function available(): array
    {
        return array_values(array_filter(
            $this->batchRepository->findAll(self::TYPE, 'own'),
            fn (BatchEntity $batch) => $batch->isActive()
        ));
    }

    /**
     * @throws RegisterDeathException several death batches and none chosen, or a chosen one that is not
     */
    public function resolve(?int $chosenId): BatchEntity
    {
        $available = $this->available();

        if ($chosenId !== null) {
            foreach ($available as $batch) {
                if ((int) $batch->getId() === $chosenId) {
                    return $batch;
                }
            }

            throw RegisterDeathException::on('death_batch_id', 'El lote elegido no es un Lote de Muertes activo.');
        }

        if (count($available) === 1) {
            return $available[0];
        }

        if (count($available) > 1) {
            throw RegisterDeathException::on('death_batch_id', 'Hay varios Lotes de Muertes: elegí a cuál va.');
        }

        return $this->create();
    }

    private function create(): BatchEntity
    {
        $batchType = $this->batchTypeRepository->findByCode(self::TYPE);
        $activity = $this->activityRepository->findByCode('INTERNAL');

        $saved = $this->batchRepository->save(new BatchEntity(
            id: null,
            name: 'Lote de Muertes',
            farmId: null,
            observaciones: 'Creado al registrar la primera muerte.',
            isActive: true,
            createdAt: new \DateTimeImmutable(),
            farmName: null,
            providerId: null,
            providerName: null,
            activityId: $activity?->getId(),
            activityName: $activity?->getName(),
            currentWeight: 0.0,
            caravansCount: 0,
            batchTypeId: $batchType?->getId(),
            batchTypeName: $batchType?->getName(),
            batchTypeCode: self::TYPE
        ));

        // Opening point of its weight series, like the reserve batch: no animals, no average.
        $this->batchRepository->addWeight(
            $saved->getId(),
            null,
            'INITIAL',
            new \DateTimeImmutable(),
            $activity?->getId(),
            totalWeight: 0.0,
            caravansCount: 0,
            weighedCount: 0,
            weightsAsOf: null
        );

        return $saved;
    }
}
