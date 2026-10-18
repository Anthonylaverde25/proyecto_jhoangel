<?php

declare(strict_types=1);

namespace App\Application\UseCases\FiscalDocuments;

use App\Application\DTOs\Stock\FiscalDocumentDTO;
use App\Application\Services\Stock\FiscalDocumentBuilder;
use App\Application\Services\Stock\StockLedger;
use App\Core\Entities\FiscalDocumentEntity;
use App\Core\Entities\FiscalDocumentLineEntity;
use App\Core\Entities\ItemDocumentEntity;
use App\Core\Entities\ItemDocumentLineEntity;
use App\Core\Enums\ItemDocumentType;
use App\Core\Interfaces\IFiscalDocumentRepository;
use App\Core\Interfaces\IWarehouseRepository;
use Illuminate\Support\Facades\DB;

/**
 * Registers an invoice, credit note or ticket (plan 5.3). In the same transaction, its stock-tracked
 * lines become one purchase receipt (invoice, ticket) or one return (credit note); services and
 * non-stock articles stay on the document, charged to their batch (DS14).
 */
final class RegisterFiscalDocumentUseCase
{
    public function __construct(
        private readonly IFiscalDocumentRepository $documents,
        private readonly IWarehouseRepository $warehouses,
        private readonly FiscalDocumentBuilder $builder,
        private readonly StockLedger $ledger,
    ) {
    }

    public function __invoke(FiscalDocumentDTO $dto, int $companyId): FiscalRegistration
    {
        $existing = $this->documents->findByIdempotencyKey($dto->idempotencyKey, $companyId);
        if ($existing !== null) {
            return new FiscalRegistration($existing, [], false);
        }

        return DB::transaction(function () use ($dto, $companyId) {
            $saved = $this->documents->create($this->builder->build($dto, $companyId, now()->toDateString()));

            $incidents = [];
            $operation = $saved->getType()->kind->stockOperation();
            $stocked = $saved->stockedLines();
            if ($operation !== null && $stocked !== []) {
                $draft = $this->operation($saved, $operation, $stocked, $companyId, $dto->createdBy);
                $result = $operation === ItemDocumentType::PURCHASE
                    ? $this->ledger->receive($draft, $dto->createdBy)
                    : $this->ledger->issue($draft, $dto->createdBy);
                $incidents = $result->incidents;
            }

            return new FiscalRegistration($this->documents->findById((int) $saved->getId(), $companyId) ?? $saved, $incidents, true);
        });
    }

    /** @param list<FiscalDocumentLineEntity> $stocked */
    private function operation(FiscalDocumentEntity $document, ItemDocumentType $type, array $stocked, int $companyId, ?int $userId): ItemDocumentEntity
    {
        $lines = [];
        foreach ($stocked as $index => $line) {
            $lines[] = new ItemDocumentLineEntity(
                $index + 1,
                $line->itemId,
                $line->inputQuantity,
                $line->inputUnitId,
                $line->inputPresentationId,
                $line->quantity,
                // A purchase enters at the line's cost; a return leaves at the average (valued by the ledger).
                unitCost: $type === ItemDocumentType::PURCHASE ? $line->unitCost : null,
                totalCost: $type === ItemDocumentType::PURCHASE ? $line->costAmount : null,
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
