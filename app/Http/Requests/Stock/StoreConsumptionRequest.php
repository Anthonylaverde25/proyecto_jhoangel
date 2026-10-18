<?php

declare(strict_types=1);

namespace App\Http\Requests\Stock;

/**
 * A consumption, charged to a batch or to general use.
 */
class StoreConsumptionRequest extends StoreStockOperationRequest
{
    protected function declaresCost(): bool
    {
        return false;
    }
}
