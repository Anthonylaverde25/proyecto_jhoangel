<?php

declare(strict_types=1);

namespace App\Application\UseCases\FiscalDocuments;

use App\Application\Services\Stock\StockLedger;
use App\Core\Entities\FiscalDocumentEntity;
use App\Core\Entities\FiscalDocumentLineEntity;
use App\Core\Enums\FiscalDocumentKind;
use App\Core\Exceptions\FiscalDocumentException;
use App\Core\Interfaces\IFiscalDiscrepancyRepository;
use App\Core\Interfaces\IFiscalDocumentRepository;
use App\Core\Interfaces\IItemDocumentRepository;
use App\Core\Interfaces\IUnvaluedLotRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Voids a provider document and, in the same transaction, reverses the stock operations it generated
 * (DS20). If the reversal is refused (plan 4.7) nothing is voided. Once voided, the same number can
 * be loaded again (DS21).
 *
 * Delivery C (part 5 §5.7): a delivery note already invoiced is voided only after its invoice. Voiding
 * an invoice converted from delivery notes frees them, puts their lots back to pending and cancels
 * its discrepancies.
 */
final class VoidFiscalDocumentUseCase
{
    public function __construct(
        private readonly IFiscalDocumentRepository $documents,
        private readonly IItemDocumentRepository $operations,
        private readonly StockLedger $ledger,
        private readonly IUnvaluedLotRepository $lots,
        private readonly IFiscalDiscrepancyRepository $discrepancies,
    ) {
    }

    public function __invoke(int $id, string $reason, int $companyId, ?int $userId): FiscalDocumentEntity
    {
        $document = $this->documents->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Fiscal document {$id} not found.");

        DB::transaction(function () use ($document, $reason, $companyId, $userId) {
            $what = $this->withArticle($document);
            if ($document->isDeliveryNote() && $document->getInvoicedByDocumentId() !== null && !$document->isVoided()) {
                throw FiscalDocumentException::on('domain', "No se puede anular {$what}: está en la {$document->getInvoicedByLabel()}. Anulá primero la factura.");
            }

            $document->void($reason, now()->toIso8601String());

            // One operation per warehouse: an invoice converted from notes of two warehouses values both (H3).
            foreach ($this->operations->findAllByFiscalDocument((int) $document->getId(), $companyId) as $operation) {
                if ($operation->getReversedById() === null) {
                    $this->ledger->reverse($operation, $what, $reason, now()->toDateTimeString(), null, $userId);
                }
            }

            $noteIds = $this->documents->deliveryNoteIdsOf((int) $document->getId(), $companyId);
            if ($noteIds !== []) {
                $this->unvalue($document, $companyId);
                $this->documents->markInvoiced($noteIds, null, $companyId);
                $this->discrepancies->cancelForInvoice((int) $document->getId(), $companyId);
            }

            $this->documents->markVoided($document, $userId);
        });

        return $this->documents->findById($id, $companyId) ?? $document;
    }

    /** The lots this invoice valued wait for an invoice again, and so do their issues (model 2.8). */
    private function unvalue(FiscalDocumentEntity $invoice, int $companyId): void
    {
        $lineIds = array_map(fn (FiscalDocumentLineEntity $line) => (int) $line->id, $invoice->getLines());
        foreach ($this->lots->lockValuedBy($lineIds, $companyId) as $lot) {
            $lot->unvalue();
            $this->lots->save($lot);
            $this->lots->setIssueCosts((int) $lot->getId(), null, $companyId);
        }
    }

    /** "la Factura A 0001-00000450", "el Remito 0001-00000123". */
    private function withArticle(FiscalDocumentEntity $document): string
    {
        $masculine = in_array($document->getType()->kind, [FiscalDocumentKind::DELIVERY_NOTE, FiscalDocumentKind::TICKET], true);

        return ($masculine ? 'el ' : 'la ') . $document->label();
    }
}
