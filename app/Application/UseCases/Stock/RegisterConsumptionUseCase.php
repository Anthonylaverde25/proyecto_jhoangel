<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Application\DTOs\Stock\StockOperationDTO;
use App\Application\Services\Stock\BatchConsumptionGuard;
use App\Application\Services\Stock\FieldErrorBag;
use App\Application\Services\Stock\ItemLineResolver;
use App\Application\Services\Stock\LedgerResult;
use App\Application\Services\Stock\StockLedger;
use App\Application\Services\Stock\WarehouseGuard;
use App\Core\Entities\ItemDocumentEntity;
use App\Core\Entities\ItemDocumentLineEntity;
use App\Core\Enums\ItemDocumentType;
use App\Core\Exceptions\ItemDocumentException;
use App\Core\Interfaces\IItemDocumentRepository;

/**
 * Stock-tracked articles taken out of the warehouse, charged to one batch with the heads that
 * received them, or to general use without a batch (D20, D22). They leave at the average cost; a
 * balance that goes negative is recorded anyway and raises a notice (D5).
 */
final class RegisterConsumptionUseCase
{
    public function __construct(
        private readonly IItemDocumentRepository $documents,
        private readonly WarehouseGuard $warehouses,
        private readonly ItemLineResolver $resolver,
        private readonly BatchConsumptionGuard $batches,
        private readonly StockLedger $ledger,
    ) {
    }

    public function __invoke(StockOperationDTO $dto, int $companyId): LedgerResult
    {
        $existing = $this->documents->findByIdempotencyKey($dto->idempotencyKey, $companyId);
        if ($existing !== null) {
            return new LedgerResult($existing, [], true);
        }

        $errors = new FieldErrorBag();
        if (substr($dto->occurredAt, 0, 10) > now()->toDateString()) {
            $errors->add('occurred_at', 'La fecha no puede ser futura.');
        }
        $warehouse = $this->warehouses->active($companyId, $dto->warehouseId, 'warehouse_id', $errors);
        if ($dto->batchId !== null) {
            $this->batches->check($dto->batchId, $dto->headsTreated, $companyId, '', $errors);
        }
        if ($dto->lines === []) {
            $errors->add('lines', 'El consumo no tiene renglones.');
        }

        $lines = [];
        $seen = [];
        foreach ($dto->lines as $index => $line) {
            // E7: two lines of the same article in one consumption are almost always a typing slip.
            if (isset($seen[$line->itemId])) {
                $errors->add("lines.{$index}.item_id", 'El artículo está dos veces: sumá las cantidades en un renglón.');
                continue;
            }
            $seen[$line->itemId] = true;
            $resolved = $this->resolver->resolve("lines.{$index}", $companyId, $line->itemId, $line->inputQuantity, $line->inputUnitId, $line->inputPresentationId, $errors, true);
            if ($resolved !== null) {
                $lines[] = new ItemDocumentLineEntity(
                    $index + 1,
                    (int) $resolved->item->getId(),
                    $resolved->inputQuantity->toScale(4),
                    $resolved->inputUnitId,
                    $resolved->inputPresentationId,
                    $resolved->quantity->toScale(4),
                );
            }
        }
        $errors->throwIfAny(ItemDocumentException::class);

        return $this->ledger->issue(new ItemDocumentEntity(
            null, $companyId, null, ItemDocumentType::CONSUMPTION, $warehouse->id, $dto->occurredAt, $lines,
            batchId: $dto->batchId,
            headsTreated: $dto->batchId === null ? null : $dto->headsTreated,
            notes: $dto->notes,
            idempotencyKey: $dto->idempotencyKey,
            createdBy: $dto->createdBy,
        ), $dto->createdBy);
    }
}
