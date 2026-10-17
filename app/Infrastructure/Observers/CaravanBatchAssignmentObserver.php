<?php

declare(strict_types=1);

namespace App\Infrastructure\Observers;

use App\Models\Caravan;

/**
 * Stamps when a caravan joins a batch on every Eloquent write. The repository methods that move
 * caravans with the query builder (no model events) stamp the date themselves.
 */
final class CaravanBatchAssignmentObserver
{
    public function saving(Caravan $caravan): void
    {
        if (!$caravan->isDirty('batch_id')) {
            return;
        }

        if ($caravan->batch_id === null) {
            $caravan->batch_assigned_at = null;

            return;
        }

        // A caller that knows the real date (a backdated movement) sets it on purpose.
        if (!$caravan->isDirty('batch_assigned_at')) {
            $caravan->batch_assigned_at = now();
        }
    }
}
