<?php

declare(strict_types=1);

namespace App\Application\UseCases\FiscalDocuments;

use App\Application\Services\Stock\StockLedger;
use App\Core\Entities\FiscalDocumentEntity;
use App\Core\Interfaces\IFiscalDocumentRepository;
use App\Core\Interfaces\IItemDocumentRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Voids a provider document and, in the same transaction, reverses the stock operation it generated
 * (DS20). If the reversal is refused (plan 4.7) nothing is voided. Once voided, the same number can
 * be loaded again (DS21).
 */
final class VoidFiscalDocumentUseCase
{
    public function __construct(
        private readonly IFiscalDocumentRepository $documents,
        private readonly IItemDocumentRepository $operations,
        private readonly StockLedger $ledger,
    ) {
    }

    public function __invoke(int $id, string $reason, int $companyId, ?int $userId): FiscalDocumentEntity
    {
        $document = $this->documents->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Fiscal document {$id} not found.");

        DB::transaction(function () use ($document, $reason, $companyId, $userId) {
            $document->void($reason, now()->toIso8601String());

            $operation = $this->operations->findByFiscalDocument((int) $document->getId(), $companyId);
            if ($operation !== null && $operation->getReversedById() === null) {
                $this->ledger->reverse($operation, "la {$document->label()}", $reason, now()->toDateTimeString(), null, $userId);
            }

            $this->documents->markVoided($document, $userId);
        });

        return $this->documents->findById($id, $companyId) ?? $document;
    }
}
