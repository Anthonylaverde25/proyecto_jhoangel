<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Entities\BatchEntity;
use App\Core\Exceptions\ExitOrderDomainException;
use App\Core\Interfaces\IActivityRepository;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\IBatchTypeRepository;
use App\Core\ValueObjects\TerminalBatchTypes;

/**
 * The exit batch an animal that left goes to (P4): the only one the company has, the one chosen
 * when it has several, or a new "Lote de Egresos" when it has none. A copy of DeathBatchResolver.
 */
final class ExitBatchResolver
{
    public const TYPE = TerminalBatchTypes::EXIT;

    public function __construct(
        private readonly IBatchRepository $batchRepository,
        private readonly IBatchTypeRepository $batchTypeRepository,
        private readonly IActivityRepository $activityRepository
    ) {
    }

    /**
     * @return list<BatchEntity> the active exit batches of the company
     */
    public function available(): array
    {
        return array_values(array_filter(
            $this->batchRepository->findAll(self::TYPE, 'own'),
            fn (BatchEntity $batch) => $batch->isActive()
        ));
    }

    /**
     * @throws ExitOrderDomainException several exit batches and none chosen, or a chosen one that is not
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

            throw ExitOrderDomainException::on('exit_batch_id', 'El lote elegido no es un Lote de Egresos activo.');
        }

        if (count($available) === 1) {
            return $available[0];
        }

        if (count($available) > 1) {
            throw ExitOrderDomainException::on('exit_batch_id', 'Hay varios Lotes de Egresos: elegí a cuál van.');
        }

        return $this->create();
    }

    private function create(): BatchEntity
    {
        $batchType = $this->batchTypeRepository->findByCode(self::TYPE);
        $activity = $this->activityRepository->findByCode('INTERNAL');

        $saved = $this->batchRepository->save(new BatchEntity(
            id: null,
            name: 'Lote de Egresos',
            farmId: null,
            observaciones: 'Creado al registrar el primer egreso.',
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

        // Opening point of its weight series, like the death batch: no animals, no average.
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
