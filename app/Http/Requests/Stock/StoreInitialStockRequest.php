<?php

declare(strict_types=1);

namespace App\Http\Requests\Stock;

/**
 * What the company had when it started, with its declared cost.
 */
class StoreInitialStockRequest extends StoreStockOperationRequest
{
    protected function declaresCost(): bool
    {
        return true;
    }
}
