<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeaningOrders;

final class WeaningOrderUseCases
{
    public function __construct(
        public readonly CreateWeaningOrderUseCase $create,
        public readonly UpdateWeaningOrderDraftUseCase $updateDraft,
        public readonly IssueWeaningOrderUseCase $issue,
        public readonly GetWeaningOrderUseCase $get,
        public readonly FindWeaningOrderByCodeUseCase $findByCode,
        public readonly ListWeaningOrdersUseCase $list,
        public readonly MarkWeaningOrderPrintedUseCase $markPrinted,
        public readonly CloseIncompleteWeaningOrderUseCase $closeIncomplete,
        public readonly CancelWeaningOrderUseCase $cancel,
        public readonly ExecuteWeaningOrderUseCase $execute,
        public readonly RegisterWeaningUseCase $register,
    ) {
    }
}
