<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Application\DTOs\Stock\StockTransferDTO;
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
 * Moves stock-tracked articles from one active warehouse of the company to another (DD7). What may
 * move is checked by the ledger against the locked balances: only valued units, never below zero (DD8).
 */
final class RegisterStockTransferUseCase
{
    public function __construct(
        private readonly IItemDocumentRepository $documents,
        private readonly WarehouseGuard $warehouses,
        private readonly ItemLineResolver $resolver,
        private readonly StockLedger $ledger,
    ) {
    }

    public function __invoke(StockTransferDTO $dto, int $companyId): LedgerResult
    {
        $existing = $this->documents->findByIdempotencyKey($dto->idempotencyKey, $companyId);
        if ($existing !== null) {
            return new LedgerResult($existing, [], true);
        }

        $errors = new FieldErrorBag();
        if (substr($dto->occurredAt, 0, 10) > now()->toDateString()) {
            $errors->add('occurred_at', 'La fecha no puede ser futura.');
        }
        $from = $this->warehouses->active($companyId, $dto->fromWarehouseId, 'from_warehouse_id', $errors);
        $to = $this->warehouses->active($companyId, $dto->toWarehouseId, 'to_warehouse_id', $errors);
        if ($dto->fromWarehouseId === $dto->toWarehouseId) {
            $errors->add('to_warehouse_id', 'El destino tiene que ser otro almacén.');
        }
        if ($dto->lines === []) {
            $errors->add('lines', 'La transferencia no tiene renglones.');
        }

        $lines = [];
        $items = [];
        foreach ($dto->lines as $index => $line) {
            // Same rule as a consumption (E7): two lines of one article are almost always a typing slip.
            if (isset($items[$line->itemId])) {
                $errors->add("lines.{$index}.item_id", 'El artículo está dos veces: sumá las cantidades en un renglón.');
                continue;
            }
            $resolved = $this->resolver->resolve("lines.{$index}", $companyId, $line->itemId, $line->inputQuantity, $line->inputUnitId, $line->inputPresentationId, $errors, true);
            $items[$line->itemId] = ['name' => '', 'symbol' => ''];
            if ($resolved !== null) {
                $items[$line->itemId] = ['name' => $resolved->item->getName(), 'symbol' => (string) $resolved->item->getUnit()?->getSymbol()];
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

        return $this->ledger->transfer(new ItemDocumentEntity(
            null, $companyId, null, ItemDocumentType::TRANSFER_OUT, $from->id, $dto->occurredAt, $lines,
            notes: $dto->notes,
            idempotencyKey: $dto->idempotencyKey,
            createdBy: $dto->createdBy,
        ), $to->id, $from->code, $items, $dto->createdBy);
    }
}
