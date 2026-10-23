<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Application\DTOs\Stock\StockTransferDTO;
use App\Application\Services\Stock\FieldErrorBag;
use App\Application\Services\Stock\ItemLineResolver;
use App\Application\Services\Stock\LedgerResult;
use App\Application\Services\Stock\SemenLotLineGuard;
use App\Application\Services\Stock\SemenStorageRule;
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
        private readonly SemenStorageRule $semen,
        private readonly SemenLotLineGuard $lots,
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
        $seen = [];
        foreach ($dto->lines as $index => $line) {
            // Same rule as a consumption (E7, DA4): per article, and per lot for semen.
            $key = "{$line->itemId}|{$line->semenLotId}";
            if (isset($seen[$key])) {
                $errors->add("lines.{$index}.item_id", $line->semenLotId === null
                    ? 'El artículo está dos veces: sumá las cantidades en un renglón.'
                    : 'La partida está dos veces: sumá las cantidades en un renglón.');
                continue;
            }
            $seen[$key] = true;
            $resolved = $this->resolver->resolve("lines.{$index}", $companyId, $line->itemId, $line->inputQuantity, $line->inputUnitId, $line->inputPresentationId, $errors, true);
            $items[$line->itemId] = ['name' => '', 'symbol' => ''];
            if ($resolved !== null && $to !== null) {
                // Semen may leave a general warehouse towards a tank, never the other way (SI6).
                $this->semen->check($resolved->item, $to, "lines.{$index}.item_id", $errors);
            }
            if ($resolved !== null) {
                $items[$line->itemId] = ['name' => $resolved->item->getName(), 'symbol' => (string) $resolved->item->getUnit()?->getSymbol()];
                $lines[] = new ItemDocumentLineEntity(
                    $index + 1,
                    (int) $resolved->item->getId(),
                    $resolved->inputQuantity->toScale(4),
                    $resolved->inputUnitId,
                    $resolved->inputPresentationId,
                    $resolved->quantity->toScale(4),
                    semenLotId: $this->lots->check("lines.{$index}", $resolved->item, $line->semenLotId, false, $errors),
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
