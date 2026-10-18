<?php

declare(strict_types=1);

namespace App\Application\UseCases\DeliveryNotes;

final class DeliveryNoteUseCases
{
    public function __construct(
        public readonly ListPendingDeliveryNotesUseCase $pending,
        public readonly BuildInvoiceDraftUseCase $invoiceDraft,
        public readonly ListFiscalDiscrepanciesUseCase $discrepancies,
        public readonly ResolveFiscalDiscrepancyUseCase $resolveDiscrepancy,
        public readonly CountFiscalDiscrepanciesUseCase $countDiscrepancies,
    ) {
    }
}
