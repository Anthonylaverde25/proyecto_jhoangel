<?php

declare(strict_types=1);

namespace App\Application\UseCases\FiscalDocuments;

use App\Application\DTOs\Stock\FiscalDocumentDTO;
use App\Application\Services\Stock\FiscalDocumentBuilder;
use App\Application\Services\Stock\StockLedger;
use App\Application\Services\Stock\ValuationEntry;
use App\Core\Entities\FiscalDocumentEntity;
use App\Core\Entities\FiscalDocumentLineEntity;
use App\Core\Entities\ItemDocumentEntity;
use App\Core\Entities\ItemDocumentLineEntity;
use App\Core\Enums\ItemDocumentType;
use App\Core\Interfaces\IFiscalDiscrepancyRepository;
use App\Core\Interfaces\IFiscalDocumentRepository;
use App\Core\Interfaces\IUnvaluedLotRepository;
use App\Core\Interfaces\IWarehouseRepository;
use App\Core\Services\DeliveryNoteInvoiceComparator;
use Illuminate\Support\Facades\DB;

/**
 * Registers an invoice, credit note, ticket or delivery note (plan 5.3). In the same transaction, its
 * stock-tracked lines become one purchase receipt (invoice, ticket), one return (credit note) or one
 * delivery receipt (delivery note); services and non-stock articles stay on the document, charged to
 * their batch (DS14). An invoice converted from delivery notes moves no quantities (DC1): it values
 * what arrived unpriced and records every difference with the notes (delivery C, part 5 §5.4).
 */
final class RegisterFiscalDocumentUseCase
{
    public function __construct(
        private readonly IFiscalDocumentRepository $documents,
        private readonly IWarehouseRepository $warehouses,
        private readonly FiscalDocumentBuilder $builder,
        private readonly StockLedger $ledger,
        private readonly IUnvaluedLotRepository $lots,
        private readonly DeliveryNoteInvoiceComparator $comparator,
        private readonly IFiscalDiscrepancyRepository $discrepancies,
    ) {
    }

    public function __invoke(FiscalDocumentDTO $dto, int $companyId): FiscalRegistration
    {
        $existing = $this->documents->findByIdempotencyKey($dto->idempotencyKey, $companyId);
        if ($existing !== null) {
            return new FiscalRegistration($existing, [], false);
        }

        return DB::transaction(function () use ($dto, $companyId) {
            $built = $this->builder->build($dto, $companyId, now()->toDateString());
            $saved = $this->documents->create($built);

            $incidents = [];
            $noteIds = $built->getDeliveryNoteIds();
            if ($noteIds !== []) {
                $this->documents->markInvoiced($noteIds, (int) $saved->getId(), $companyId);
                $incidents = $this->convert($saved, $noteIds, $companyId, $dto->createdBy);
            } else {
                $operation = $saved->getType()->kind->stockOperation();
                $stocked = $saved->stockedLines();
                if ($operation !== null && $stocked !== []) {
                    $draft = $this->operation($saved, $operation, $stocked, $companyId, $dto->createdBy);
                    $result = $operation === ItemDocumentType::RETURN_TO_PROVIDER
                        ? $this->ledger->issue($draft, $dto->createdBy)
                        : $this->ledger->receive($draft, $dto->createdBy);
                    $incidents = $result->incidents;
                }
            }

            return new FiscalRegistration($this->documents->findById((int) $saved->getId(), $companyId) ?? $saved, $incidents, true);
        });
    }

    /**
     * Values the lots of the unvalued notes and the non-recoverable taxes of the valued ones (Q3), and
     * records the differences (DC7). Returns the stock notices of the valuation.
     *
     * @param list<int> $noteIds
     * @return list<\App\Core\Entities\StockIncidentEntity>
     */
    private function convert(FiscalDocumentEntity $invoice, array $noteIds, int $companyId, ?int $userId): array
    {
        $notes = [];
        $origins = [];
        foreach ($this->documents->lockDeliveryNotes($noteIds, $companyId) as $note) {
            $notes[] = $note;
            foreach ($note->getLines() as $line) {
                $origins[(int) $line->id] = $note;
            }
        }
        $lots = $this->lots->lockByDeliveryNoteLines(array_keys($origins), $companyId);

        $entries = [];
        foreach ($invoice->getLines() as $line) {
            $note = $line->deliveryNoteLineId === null ? null : ($origins[$line->deliveryNoteLineId] ?? null);
            if ($note === null) {
                continue;
            }
            $lot = $lots[$line->deliveryNoteLineId] ?? null;
            if ($note->isValued() === false && $lot !== null && $lot->isPending()) {
                $entries[] = new ValuationEntry($line, $lot);
            } elseif ($note->isValued() === true && bccomp($line->nonRecoverableTaxAmount, '0', 4) > 0) {
                $entries[] = new ValuationEntry($line, taxAmount: $line->nonRecoverableTaxAmount);
            }
        }

        $result = $this->ledger->valuate(
            $companyId,
            $this->warehouses->ensureDefault($companyId)->id,
            (int) $invoice->getId(),
            $invoice->getIssuedAt() . ' 00:00:00',
            $entries,
            $userId,
        );

        $this->discrepancies->createMany($this->comparator->compare($invoice, $notes, $userId));

        return $result?->incidents ?? [];
    }

    /** @param list<FiscalDocumentLineEntity> $stocked */
    private function operation(FiscalDocumentEntity $document, ItemDocumentType $type, array $stocked, int $companyId, ?int $userId): ItemDocumentEntity
    {
        // A receipt enters at the line's cost (none on an unvalued delivery note); a return leaves at the average.
        $entersAtCost = $type !== ItemDocumentType::RETURN_TO_PROVIDER;
        $lines = [];
        foreach ($stocked as $index => $line) {
            $lines[] = new ItemDocumentLineEntity(
                $index + 1,
                $line->itemId,
                $line->inputQuantity,
                $line->inputUnitId,
                $line->inputPresentationId,
                $line->quantity,
                unitCost: $entersAtCost ? $line->unitCost : null,
                totalCost: $entersAtCost ? $line->costAmount : null,
                fiscalDocumentLineId: $line->id,
            );
        }

        return new ItemDocumentEntity(
            null,
            $companyId,
            null,
            $type,
            $this->warehouses->ensureDefault($companyId)->id,
            $document->getIssuedAt() . ' 00:00:00',
            $lines,
            fiscalDocumentId: $document->getId(),
            createdBy: $userId,
        );
    }
}
