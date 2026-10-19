<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

final class ItemDocumentUseCases
{
    public function __construct(
        public readonly SearchItemDocumentsUseCase $search,
        public readonly FindItemDocumentUseCase $find,
        public readonly RegisterInitialStockUseCase $registerInitialStock,
        public readonly RegisterConsumptionUseCase $registerConsumption,
        public readonly RegisterStockCountUseCase $registerCount,
        public readonly ReverseItemDocumentUseCase $reverse,
        public readonly RegisterStockTransferUseCase $registerTransfer,
    ) {
    }
}
