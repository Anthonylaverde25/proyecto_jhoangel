<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Activity;
use App\Models\BatchType;
use Illuminate\Contracts\Validation\Validator;

/**
 * Two catalogue rules shared by every path that creates a batch (direct creation
 * and creation of the destination batch of a caravan transfer):
 *
 * 1. The batch type must belong to the assigned activity, unless the type is
 *    cross-cutting (`activity_id === null`) and therefore valid everywhere. A batch
 *    created without an activity accepts any type.
 * 2. The management system is a fact only the producer knows, so it must be declared
 *    in every productive activity. It used to be demanded in Recría alone, but nothing
 *    in the domain ever restricted it there: a Cría batch can be penned just like a
 *    Recría one, `ChangeBatchManagementUseCase` has always accepted any batch, and the
 *    migration that created the column already called it orthogonal to the batch type.
 *    Leaving it undeclared would turn the column default into a system guess.
 */
trait ValidatesBatchClassification
{
    private const NON_PRODUCTIVE_ACTIVITY = 'INTERNAL';

    protected function validateBatchClassification(
        Validator $validator,
        mixed $activityId,
        mixed $batchTypeId,
        bool $hasIsConfined,
        string $batchTypeKey = 'batch_type_id',
        string $isConfinedKey = 'is_confined'
    ): void {
        if ($activityId && $batchTypeId) {
            $batchType = BatchType::find($batchTypeId);

            if ($batchType && $batchType->activity_id !== null
                && (int) $batchType->activity_id !== (int) $activityId) {
                $validator->errors()->add(
                    $batchTypeKey,
                    'El tipo de lote seleccionado no corresponde a la actividad asignada.'
                );
            }
        }

        if ($activityId && !$hasIsConfined) {
            $activity = Activity::find($activityId);

            // INTERNAL is not a productive stage: it is where the system's own batches
            // live, such as the reserve batch. Asking how the reserve batch is fed makes
            // no sense. If a second non-productive activity ever appears, the right test
            // is the `is_enabled` flag rather than a list of codes.
            if ($activity && $activity->code !== self::NON_PRODUCTIVE_ACTIVITY) {
                $validator->errors()->add(
                    $isConfinedKey,
                    'Indicá si el lote se maneja a corral o de forma extensiva.'
                );
            }
        }
    }
}
