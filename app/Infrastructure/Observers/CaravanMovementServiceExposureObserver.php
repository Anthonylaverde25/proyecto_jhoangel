<?php

declare(strict_types=1);

namespace App\Infrastructure\Observers;

use App\Application\Services\ServiceExposureSynchronizer;
use App\Models\CaravanMovement;

/**
 * Hooks the service exposure to the movement record, not to caravans.batch_id: batch changes
 * are often written with the query builder (no model events), while every path that moves an
 * animal records its movement through Eloquent.
 */
final class CaravanMovementServiceExposureObserver
{
    public function __construct(
        private readonly ServiceExposureSynchronizer $synchronizer
    ) {
    }

    public function created(CaravanMovement $movement): void
    {
        $movementDate = $movement->movement_date instanceof \DateTimeInterface
            ? $movement->movement_date->format('Y-m-d')
            : (string) ($movement->movement_date ?? now()->format('Y-m-d'));

        $this->synchronizer->onMovement(
            (int) $movement->caravan_id,
            $movement->from_batch_id !== null ? (int) $movement->from_batch_id : null,
            $movement->to_batch_id !== null ? (int) $movement->to_batch_id : null,
            $movementDate,
            (string) $movement->type
        );
    }
}
