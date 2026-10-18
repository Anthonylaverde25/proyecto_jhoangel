<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

final class StockUseCases
{
    public function __construct(
        public readonly GetItemStockUseCase $itemStock,
        public readonly GetItemKardexUseCase $kardex,
        public readonly SetCriticalStockUseCase $setCritical,
        public readonly ListWarehousesUseCase $warehouses,
        public readonly GetBatchConsumptionHistoryUseCase $batchHistory,
        public readonly SummarizeItemsStockUseCase $summarizeItems,
        public readonly DescribeItemUsageUseCase $describeItem,
    ) {
    }
}
