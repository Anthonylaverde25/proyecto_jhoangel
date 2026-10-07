<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

final class EntryOrderUseCases
{
    public function __construct(
        public readonly CreateEntryOrderUseCase $create,
        public readonly UpdateEntryOrderDraftUseCase $updateDraft,
        public readonly ConfirmEntryOrderUseCase $confirm,
        public readonly LoadEntryOrderDteUseCase $loadDte,
        public readonly CorrectEntryOrderDteHeadCountUseCase $correctDteHeadCount,
        public readonly RegisterEntryUseCase $register,
        public readonly ReceiveEntryOrderUseCase $receive,
        public readonly ReadTriUseCase $readTri,
        public readonly ResolveEntryOrderIncidentUseCase $resolveIncident,
        public readonly CloseIncompleteEntryOrderUseCase $closeIncomplete,
        public readonly CancelEntryOrderUseCase $cancel,
        public readonly MarkEntryOrderPrintedUseCase $markPrinted,
        public readonly IssueReceiptSheetUseCase $issueReceiptSheet,
        public readonly MarkReceiptSheetPrintedUseCase $markReceiptSheetPrinted,
        public readonly ConfigureReceiptSheetUseCase $configureReceiptSheet,
        public readonly GetEntryOrderUseCase $get,
        public readonly FindEntryOrderByCodeUseCase $findByCode,
        public readonly ListEntryOrdersUseCase $list,
        public readonly PeekNextEntryOrderNumberUseCase $peekNextNumber,
    ) {
    }
}
