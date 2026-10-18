<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Application\DTOs\Stock\StockOperationDTO;
use App\Application\Services\Stock\FieldErrorBag;
use App\Application\Services\Stock\ItemLineResolver;
use App\Application\Services\Stock\LedgerResult;
use App\Application\Services\Stock\StockLedger;
use App\Core\Entities\ItemDocumentEntity;
use App\Core\Entities\ItemDocumentLineEntity;
use App\Core\Enums\ItemDocumentType;
use App\Core\Exceptions\ItemDocumentException;
use App\Core\Interfaces\IItemDocumentRepository;
use App\Core\Interfaces\IWarehouseRepository;
use App\Core\Services\FiscalDocumentCalculator;
use App\Core\ValueObjects\Decimal;

/**
 * What the company already had when it started using the system, with its declared cost (0 when
 * unknown). Stock-tracked articles only.
 */
final class RegisterInitialStockUseCase
{
    public function __construct(
        private readonly IItemDocumentRepository $documents,
        private readonly IWarehouseRepository $warehouses,
        private readonly ItemLineResolver $resolver,
        private readonly FiscalDocumentCalculator $calculator,
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
        $lines = [];
        foreach ($dto->lines as $index => $line) {
            $resolved = $this->resolver->resolve("lines.{$index}", $companyId, $line->itemId, $line->inputQuantity, $line->inputUnitId, $line->inputPresentationId, $errors, true);
            if ($line->inputUnitPrice === null) {
                $errors->add("lines.{$index}.input_unit_price", 'Falta el costo (0 si no se conoce).');
            }
            if ($resolved === null || $line->inputUnitPrice === null) {
                continue;
            }
            $price = Decimal::of($line->inputUnitPrice);
            $total = $this->calculator->lineAmount($resolved->inputQuantity, $price);
            $lines[] = new ItemDocumentLineEntity(
                $index + 1,
                (int) $resolved->item->getId(),
                $resolved->inputQuantity->toScale(4),
                $resolved->inputUnitId,
                $resolved->inputPresentationId,
                $resolved->quantity->toScale(4),
                $price->toScale(6),
                $this->calculator->unitCost($total, $resolved->quantity)->toScale(6),
                $total->toScale(4),
            );
        }
        if ($dto->lines === []) {
            $errors->add('lines', 'La carga no tiene renglones.');
        }
        $errors->throwIfAny(ItemDocumentException::class);

        return $this->ledger->receive(new ItemDocumentEntity(
            null, $companyId, null, ItemDocumentType::INITIAL, $this->warehouses->ensureDefault($companyId)->id, $dto->occurredAt, $lines,
            notes: $dto->notes, idempotencyKey: $dto->idempotencyKey, createdBy: $dto->createdBy,
        ), $dto->createdBy);
    }
}
