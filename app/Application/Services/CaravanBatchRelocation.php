<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Entities\CaravanMovementEntity;
use App\Core\Enums\BatchWeightCause;
use App\Core\Interfaces\ICaravanMovementRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Services\BatchWeightService;

/**
 * Moves animals to another batch the supported way: the batch change, one movement per animal and
 * the aggregate weight of every batch involved.
 *
 * The movement goes through the movement repository (Eloquent), so its observers run: an animal
 * leaving a serviced batch ends her exposure that day.
 */
final class CaravanBatchRelocation
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly ICaravanMovementRepository $movementRepository,
        private readonly BatchWeightService $batchWeightService
    ) {
    }

    /**
     * @param int[] $caravanIds
     * @return int[] the caravans actually moved
     */
    public function relocate(array $caravanIds, int $companyId, int $targetBatchId, string $movementDate, string $observations): array
    {
        $toMove = [];
        foreach (array_unique($caravanIds) as $caravanId) {
            $caravan = $this->caravanRepository->findById((int) $caravanId);
            if ($caravan !== null && $caravan->getBatchId() !== $targetBatchId) {
                $toMove[] = $caravan;
            }
        }

        if ($toMove === []) {
            return [];
        }

        // Closing point of every composition about to change, before anything moves.
        $sourceBatchIds = [];
        foreach ($toMove as $caravan) {
            if ($caravan->getBatchId() !== null) {
                $sourceBatchIds[$caravan->getBatchId()] = true;
            }
        }
        foreach (array_keys($sourceBatchIds) as $sourceBatchId) {
            $this->batchWeightService->snapshotBeforeMovement((int) $sourceBatchId);
        }
        $this->batchWeightService->snapshotBeforeMovement($targetBatchId);

        $this->caravanRepository->moveCaravansToBatch(
            array_map(fn ($caravan) => (int) $caravan->getId(), $toMove),
            $targetBatchId
        );

        $date = new \DateTimeImmutable(substr($movementDate, 0, 10));
        foreach ($toMove as $caravan) {
            $this->movementRepository->save(new CaravanMovementEntity(
                id: null,
                caravanId: (int) $caravan->getId(),
                companyId: $companyId,
                renspa: $caravan->getRenspa() !== '' ? $caravan->getRenspa() : 'NO_DEFINIDO',
                type: 'TRANSFER',
                movementDate: $date,
                observations: $observations,
                fromBatchId: $caravan->getBatchId(),
                toBatchId: $targetBatchId
            ));
        }

        foreach (array_keys($sourceBatchIds) as $sourceBatchId) {
            $this->batchWeightService->recalculateBatchWeight((int) $sourceBatchId, BatchWeightCause::MOVEMENT_OUT);
        }
        $this->batchWeightService->recalculateBatchWeight($targetBatchId, BatchWeightCause::MOVEMENT_IN);

        return array_map(fn ($caravan) => (int) $caravan->getId(), $toMove);
    }
}
