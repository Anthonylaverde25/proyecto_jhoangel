<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

use App\Core\Interfaces\IConsumableBatchReader;
use App\Core\ValueObjects\ConsumableBatch;

/**
 * DS10: a consumption or a service goes to an own, active batch of the company that still has
 * animals to treat.
 */
final class BatchConsumptionGuard
{
    public function __construct(
        private readonly IConsumableBatchReader $batches
    ) {
    }

    public function check(int $batchId, ?int $headsTreated, int $companyId, string $prefix, FieldErrorBag $errors): ?ConsumableBatch
    {
        $field = $prefix === '' ? 'batch_id' : "{$prefix}.batch_id";
        $batch = $this->batches->find($batchId, $companyId);
        if ($batch === null) {
            $errors->add($field, 'El lote no existe en esta empresa.');

            return null;
        }
        $refusal = $batch->refusal();
        if ($refusal !== null) {
            $errors->add($field, $refusal);

            return null;
        }
        if ($headsTreated === null || $headsTreated <= 0) {
            $errors->add($prefix === '' ? 'heads_treated' : "{$prefix}.heads_treated", 'Indicá cuántas cabezas lo recibieron.');
        }

        return $batch;
    }
}
