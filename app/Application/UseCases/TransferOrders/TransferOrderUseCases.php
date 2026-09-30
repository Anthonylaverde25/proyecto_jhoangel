<?php

declare(strict_types=1);

namespace App\Application\UseCases\TransferOrders;

final class TransferOrderUseCases
{
    public function __construct(
        public readonly CreateTransferOrderUseCase $create,
        public readonly UpdateTransferOrderDraftUseCase $updateDraft,
        public readonly IssueTransferOrderUseCase $issue,
        public readonly GetTransferOrderUseCase $get,
        public readonly FindTransferOrderByCodeUseCase $findByCode,
        public readonly ListTransferOrdersUseCase $list,
        public readonly MarkTransferOrderPrintedUseCase $markPrinted,
        public readonly CloseIncompleteTransferOrderUseCase $closeIncomplete,
        public readonly CancelTransferOrderUseCase $cancel,
        public readonly ExecuteTransferOrderUseCase $execute,
        public readonly RegisterTransferUseCase $register,
    ) {
    }
}
