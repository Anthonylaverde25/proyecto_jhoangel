<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Application\DTOs\Stock\StockCountDTO;
use App\Application\Services\Stock\FieldErrorBag;
use App\Application\Services\Stock\ItemLineResolver;
use App\Application\Services\Stock\LedgerResult;
use App\Application\Services\Stock\ResolvedLine;
use App\Application\Services\Stock\StockLedger;
use App\Core\Exceptions\ItemDocumentException;
use App\Core\Interfaces\IItemDocumentRepository;
use App\Core\Interfaces\IWarehouseRepository;
use App\Core\ValueObjects\Decimal;

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
        $errors->throwIfAny(ItemDocumentException::class);

        if ($counted->isZero()) {
            $resolved = new ResolvedLine($resolved->item, $counted, Decimal::zero(), $resolved->inputUnitId, $resolved->inputPresentationId);
        }

        return $this->ledger->count($companyId, $warehouseId, $resolved, Decimal::of($dto->systemQuantitySeen), $dto->reason, $dto->occurredAt, $dto->idempotencyKey, $dto->createdBy);
    }
}
