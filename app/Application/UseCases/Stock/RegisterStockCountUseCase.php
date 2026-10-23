<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Application\DTOs\Stock\StockCountDTO;
use App\Application\Services\Stock\FieldErrorBag;
use App\Application\Services\Stock\ItemLineResolver;
use App\Application\Services\Stock\LedgerResult;
use App\Application\Services\Stock\ResolvedLine;
use App\Application\Services\Stock\SemenLotLineGuard;
use App\Application\Services\Stock\SemenStorageRule;
use App\Application\Services\Stock\StockLedger;
use App\Core\Exceptions\ItemDocumentException;
use App\Core\Exceptions\StaleCountException;
use App\Core\Interfaces\IItemDocumentRepository;
use App\Core\Interfaces\IItemStockRepository;
use App\Core\Interfaces\ISemenLotRepository;
use App\Core\Interfaces\IWarehouseRepository;
use App\Core\ValueObjects\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Physical count of one item in one warehouse (D18), confirmed against the balance the operator saw
 * (DS6). The counted quantity may be declared in a presentation ("7 bolsas").
 */
final class RegisterStockCountUseCase
{
    public function __construct(
        private readonly IItemDocumentRepository $documents,
        private readonly IWarehouseRepository $warehouses,
        private readonly ItemLineResolver $resolver,
        private readonly StockLedger $ledger,
        private readonly SemenStorageRule $semen,
        private readonly SemenLotLineGuard $lots,
        private readonly ISemenLotRepository $lotBalances,
        private readonly IItemStockRepository $stocks,
    ) {
    }

    public function __invoke(StockCountDTO $dto, int $companyId): LedgerResult
    {
        $existing = $this->documents->findByIdempotencyKey($dto->idempotencyKey, $companyId);
        if ($existing !== null) {
            return new LedgerResult($existing, [], true);
        }

        $errors = new FieldErrorBag();
        if (substr($dto->occurredAt, 0, 10) > now()->toDateString()) {
            $errors->add('occurred_at', 'La fecha no puede ser futura.');
        }
        $warehouseId = $dto->warehouseId ?? $this->warehouses->ensureDefault($companyId)->id;
        $owned = array_filter($this->warehouses->findAll($companyId), fn ($w) => $w->id === $warehouseId);
        if ($owned === []) {
            $errors->add('warehouse_id', 'El almacén no existe en esta empresa.');
        }

        // Nothing left is a valid count; the unit is still checked, with a quantity of one.
        $counted = Decimal::of($dto->countedQuantity);
        $resolved = $this->resolver->resolve('', $companyId, $dto->itemId, $counted->isZero() ? '1' : $dto->countedQuantity, $dto->inputUnitId, $dto->inputPresentationId, $errors, true);
        // Semen counted in a general warehouse may only be zero: what is left there goes to a tank (SI6).
        $warehouse = array_values($owned)[0] ?? null;
        if ($resolved !== null && $warehouse !== null && !$counted->isZero()) {
            $this->semen->check($resolved->item, $warehouse, 'warehouse_id', $errors);
        }
        $lotId = $resolved === null ? null : $this->lots->check('', $resolved->item, $dto->semenLotId, false, $errors);
        $errors->throwIfAny(ItemDocumentException::class);

        if ($counted->isZero()) {
            $resolved = new ResolvedLine($resolved->item, $counted, Decimal::zero(), $resolved->inputUnitId, $resolved->inputPresentationId);
        }

        if ($lotId === null) {
            return $this->ledger->count($companyId, $warehouseId, $resolved, Decimal::of($dto->systemQuantitySeen), $dto->reason, $dto->occurredAt, $dto->idempotencyKey, $dto->createdBy);
        }

        return DB::transaction(fn () => $this->countLot($companyId, $warehouseId, $lotId, $resolved, $dto));
    }

    /**
     * DA5: semen is counted per lot, in straws. What the operator saw is the lot's balance; the
     * difference adjusts the lot and, through the ledger, the item (its line records the item's new
     * total). Locks the item row first and then the lot, like the ledger (DA11).
     */
    private function countLot(int $companyId, int $warehouseId, int $lotId, ResolvedLine $resolved, StockCountDTO $dto): LedgerResult
    {
        $itemBalance = Decimal::of($this->stocks->lockForMovement($warehouseId, (int) $resolved->item->getId(), $companyId)->getQuantity());
        $lotBalance = Decimal::of($this->lotBalances->lockBalance($lotId, $warehouseId, $companyId));
        if ($lotBalance->compare(Decimal::of($dto->systemQuantitySeen)->round(4)) !== 0) {
            throw new StaleCountException($lotBalance->toScale(4));
        }
        $itemCounted = $itemBalance->add($resolved->quantity->sub($lotBalance));
        $asItem = new ResolvedLine($resolved->item, $resolved->inputQuantity, $itemCounted, $resolved->inputUnitId, $resolved->inputPresentationId);

        return $this->ledger->count($companyId, $warehouseId, $asItem, $itemBalance, $dto->reason, $dto->occurredAt, $dto->idempotencyKey, $dto->createdBy, $lotId);
    }
}
