<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

use App\Application\DTOs\Stock\AiConsumptionDTO;
use App\Core\Interfaces\IConsumableBatchReader;
use App\Core\Interfaces\IItemDocumentRepository;
use App\Core\ValueObjects\ConsumableBatch;
use App\Core\ValueObjects\Decimal;

/**
 * The rules of a consumption asked for by an AI client, shared by its preview and its registration.
 * It reuses the screen's guards and adds what only matters here: this channel charges one batch, leaves
 * semen to its insemination, and stops on what a person would see while loading it on the screen.
 * Missing stock is not a blocker: it is recorded with a negative balance and a notice (D5).
 */
final class AiConsumptionPolicy
{
    public function __construct(
        private readonly ItemLineResolver $resolver,
        private readonly SemenStorageRule $semen,
        private readonly IConsumableBatchReader $batchReader,
        private readonly BatchConsumptionGuard $batches,
        private readonly WarehouseGuard $warehouses,
        private readonly IItemDocumentRepository $documents,
    ) {
    }

    public function evaluate(AiConsumptionDTO $dto, int $companyId): AiConsumptionEvaluation
    {
        $errors = new FieldErrorBag();
        if ($dto->occurredAt > now()->toDateString()) {
            $errors->add('occurred_at', 'La fecha no puede ser futura.');
        }

        $line = $this->resolver->resolve('', $companyId, $dto->itemId, $dto->inputQuantity, $dto->inputUnitId, $dto->inputPresentationId, $errors, true);
        if ($line !== null && $this->semen->isSemen($line->item)) {
            $errors->add('item_id', "{$line->item->getName()} es semen: se descuenta al registrar la inseminación.");
        }

        $found = $this->batchReader->find($dto->batchId, $companyId);
        $heads = $dto->headsTreated ?? $found?->headCount;
        $batch = $this->batches->check($dto->batchId, $heads, $companyId, '', $errors);

        $warehouse = $this->warehouses->active($companyId, $dto->warehouseId, 'warehouse_id', $errors);
        if ($warehouse !== null && $warehouse->isCryoTank()) {
            $errors->add('warehouse_id', "{$warehouse->label()} es un termo: sólo guarda semen.");
        }

        $warnings = $errors->isEmpty() && $line !== null && $batch !== null
            ? $this->warnings($line, $batch, (int) $heads, $dto->occurredAt, $companyId)
            : [];

        return new AiConsumptionEvaluation($errors->messages(), $warnings, $line, $batch, $warehouse, $heads);
    }

    /** @return list<string> */
    private function warnings(ResolvedLine $line, ConsumableBatch $batch, int $heads, string $date, int $companyId): array
    {
        $warnings = [];
        if ($heads > $batch->headCount) {
            $warnings[] = "Indicaste {$heads} cabezas y {$batch->name} tiene {$batch->headCount} hoy.";
        }

        $itemId = (int) $line->item->getId();
        foreach ($this->documents->batchHistory($batch->id, $companyId) as $row) {
            if ($row['source'] === 'CONSUMPTION' && $row['voided'] === null && $row['item_id'] === $itemId
                && $row['occurred_at'] === $date && Decimal::of($row['quantity'])->compare($line->quantity) === 0) {
                $warnings[] = "Ya hay un consumo igual ese día: {$row['reference']['label']}.";
                break;
            }
        }

        return $warnings;
    }
}
