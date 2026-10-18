<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

final class StockIncidentUseCases
{
    public function __construct(
        public readonly ListStockIncidentsUseCase $list,
        public readonly ResolveStockIncidentUseCase $resolve,
    ) {
    }
}
