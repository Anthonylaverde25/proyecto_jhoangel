<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

use App\Core\Entities\ItemDocumentEntity;
use App\Core\Entities\ItemDocumentLineEntity;
use App\Core\Entities\ItemStockEntity;
use App\Core\Entities\StockIncidentEntity;
use App\Core\Entities\StockMovementEntity;
use App\Core\Enums\ItemDocumentType;
use App\Core\Exceptions\ItemDocumentException;
use App\Core\Exceptions\StaleCountException;
use App\Core\Interfaces\IItemDocumentRepository;
use App\Core\Interfaces\IItemStockRepository;
use App\Core\Services\WeightedAverageCost;
use App\Core\ValueObjects\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Every stock operation ends here (plan 5.2). In one transaction: lock the balance rows in item order
 * (DS7), value the lines at the locked average, insert the operation, append its movements, update
 * the balances and raise the notices. Any failure rolls everything back (I7).
 */
final class StockLedger
{
    private const QUANTITY_SCALE = 4;
    private const COST_SCALE = 6;

    public function __construct(
        private readonly IItemDocumentRepository $documents,
        private readonly IItemStockRepository $stocks,
        private readonly WeightedAverageCost $costs,
        private readonly StockIncidentRaiser $incidents,
    ) {
    }

    /** PURCHASE and INITIAL: every line enters at the cost it already carries. */
    public function receive(ItemDocumentEntity $draft, ?int $userId): LedgerResult
    {
        return $this->commit($draft->getCompanyId(), $draft->getWarehouseId(), $this->itemIds($draft), $userId, function (array $locked) use ($draft) {
            $state = $this->states($locked);
            $plans = [];
            foreach ($draft->getLines() as $line) {
                $quantity = Decimal::of($line->getQuantity());
                $cost = Decimal::of($line->getUnitCost());
                $current = &$state[$line->getItemId()];
                $after = $this->costs->enter($current['balance'], $current['average'], $quantity, $cost);
                $plans[$line->getPosition()] = $this->step(1, $quantity, $cost, Decimal::of($line->getTotalCost()), $after, $current);
                unset($current);
            }

            return [$draft, $plans];
        });
    }

    /** CONSUMPTION and RETURN_TO_PROVIDER: every line leaves at the average of the locked row. */
    public function issue(ItemDocumentEntity $draft, ?int $userId): LedgerResult
    {
        return $this->commit($draft->getCompanyId(), $draft->getWarehouseId(), $this->itemIds($draft), $userId, function (array $locked) use ($draft) {
            $state = $this->states($locked);
            $plans = [];
            foreach ($draft->getLines() as $line) {
                $quantity = Decimal::of($line->getQuantity());
                $current = &$state[$line->getItemId()];
                $average = $current['average'];
                $total = $quantity->mul($average)->round(self::QUANTITY_SCALE);
                $line->valuate($average->toScale(self::COST_SCALE), $total->toScale(self::QUANTITY_SCALE));
                $after = $this->costs->leave($current['balance'], $average, $quantity);
                $plans[$line->getPosition()] = $this->step(-1, $quantity, $average, $total, $after, $current);
                unset($current);
            }

            return [$draft, $plans];
        });
    }

    /**
     * COUNT_ADJUSTMENT of one item in one warehouse (D18). The difference is taken against the locked
     * balance, which must still be the one the operator saw (DS6). Without difference the line stays,
     * as proof the item was counted, with no movement.
     */
    public function count(
        int $companyId,
        int $warehouseId,
        ResolvedLine $resolved,
        Decimal $seen,
        ?string $reason,
        string $occurredAt,
        ?string $idempotencyKey,
        ?int $userId,
    ): LedgerResult {
        $itemId = (int) $resolved->item->getId();
        $counted = $resolved->quantity;
        $system = null;

        return $this->commit($companyId, $warehouseId, [$itemId], $userId, function (array $locked) use ($companyId, $warehouseId, $resolved, $itemId, $counted, $seen, $reason, $occurredAt, $idempotencyKey, $userId, &$system) {
            $state = $this->states($locked);
            $current = &$state[$itemId];
            $balance = $current['balance'];
            if ($balance->compare($seen->round(self::QUANTITY_SCALE)) !== 0) {
                throw new StaleCountException($balance->toScale(self::QUANTITY_SCALE));
            }
            $system = $balance->toScale(self::QUANTITY_SCALE);

            $difference = $counted->sub($balance);
            if (!$difference->isZero() && trim((string) $reason) === '') {
                throw ItemDocumentException::on('reason', 'Hay una diferencia con el sistema: indicá el motivo del ajuste.');
            }
            $quantity = $difference->abs();
            $average = $current['average'];
            $total = $quantity->mul($average)->round(self::QUANTITY_SCALE);

            $line = new ItemDocumentLineEntity(
                1, $itemId,
                $resolved->inputQuantity->toScale(self::QUANTITY_SCALE),
                $resolved->inputUnitId,
                $resolved->inputPresentationId,
                $quantity->toScale(self::QUANTITY_SCALE),
                unitCost: $average->toScale(self::COST_SCALE),
                totalCost: $total->toScale(self::QUANTITY_SCALE),
                countedQuantity: $counted->toScale(self::QUANTITY_SCALE),
            );
            $draft = new ItemDocumentEntity(
                null, $companyId, null, ItemDocumentType::COUNT_ADJUSTMENT, $warehouseId, $occurredAt, [$line],
                reason: $reason, idempotencyKey: $idempotencyKey, createdBy: $userId,
            );

            $plans = [];
            if (!$difference->isZero()) {
                $direction = $difference->isPositive() ? 1 : -1;
                $after = $direction > 0
                    ? $this->costs->enter($balance, $average, $quantity, $average)
                    : $this->costs->leave($balance, $average, $quantity);
                $plans[1] = $this->step($direction, $quantity, $average, $total, $after, $current);
            }

            return [$draft, $plans];
        }, function (ItemDocumentEntity $saved, ItemStockEntity $stock, StockMovementEntity $movement) use ($counted, &$system, $userId): array {
            return [$this->incidents->countDiscrepancy($stock, $movement, (int) $saved->getNumber(), $counted->toScale(self::QUANTITY_SCALE), (string) $system, $userId, now()->toIso8601String())];
        });
    }

    /**
     * REVERSAL: replicates every line of the original with its cost; the movement goes the other way
     * and exists only where the original moved. Undoing an entry is refused when what remains would
     * be worth less than nothing (plan 4.7).
     */
    public function reverse(ItemDocumentEntity $original, string $what, string $reason, string $occurredAt, ?string $idempotencyKey, ?int $userId): LedgerResult
    {
        $lines = array_map(fn (ItemDocumentLineEntity $line) => new ItemDocumentLineEntity(
            $line->getPosition(),
            $line->getItemId(),
            $line->getInputQuantity(),
            $line->getInputUnitId(),
            $line->getInputPresentationId(),
            $line->getQuantity(),
            $line->getInputUnitPrice(),
            $line->getUnitCost(),
            $line->getTotalCost(),
            $line->getFiscalDocumentLineId(),
            $line->getCountedQuantity(),
            $line->getId(),
        ), $original->getLines());

        $draft = new ItemDocumentEntity(
            null, $original->getCompanyId(), null, ItemDocumentType::REVERSAL, $original->getWarehouseId(), $occurredAt, $lines,
            $original->getFiscalDocumentId(), $original->getBatchId(), $original->getHeadsTreated(), $original->getId(),
            $reason, null, $idempotencyKey, $userId,
        );

        return $this->commit($draft->getCompanyId(), $draft->getWarehouseId(), $this->itemIds($draft), $userId, function (array $locked) use ($original, $draft, $what) {
            $state = $this->states($locked);
            $plans = [];
            foreach ($original->getLines() as $line) {
                $movement = $line->getMovement();
                if ($movement === null) {
                    continue;
                }
                $quantity = Decimal::of($movement->quantity);
                $cost = Decimal::of($movement->unitCost);
                $current = &$state[$line->getItemId()];
                $after = $movement->direction > 0
                    ? $this->costs->undoEntry($current['balance'], $current['average'], $quantity, $cost)
                    : $this->costs->enter($current['balance'], $current['average'], $quantity, $cost);
                if ($after === null) {
                    throw ItemDocumentException::on('domain', "No se puede anular {$what}: lo que queda de {$line->getItemName()} vale menos que lo que saldría. Cargá primero un conteo o anulá los consumos posteriores.");
                }
                $plans[$line->getPosition()] = $this->step(-$movement->direction, $quantity, $cost, Decimal::of($movement->totalCost), $after, $current);
                unset($current);
            }

            return [$draft, $plans];
        });
    }

    /**
     * @param list<int> $itemIds
     * @param callable(array<int, ItemStockEntity>): array{0: ItemDocumentEntity, 1: array<int, array<string, mixed>>} $prepare
     * @param (callable(ItemDocumentEntity, ItemStockEntity, StockMovementEntity): list<StockIncidentEntity>)|null $afterMovement
     */
    private function commit(int $companyId, int $warehouseId, array $itemIds, ?int $userId, callable $prepare, ?callable $afterMovement = null): LedgerResult
    {
        return DB::transaction(function () use ($companyId, $warehouseId, $itemIds, $userId, $prepare, $afterMovement) {
            $itemIds = array_values(array_unique($itemIds));
            sort($itemIds);
            $locked = [];
            foreach ($itemIds as $itemId) {
                $locked[$itemId] = $this->stocks->lockForMovement($warehouseId, $itemId, $companyId);
            }

            [$draft, $plans] = $prepare($locked);
            $saved = $this->persist($draft);
            $reference = mb_strtolower($saved->getType()->label()) . " N.º {$saved->getNumber()}";
            $now = now()->toIso8601String();

            $raised = [];
            foreach ($saved->getLines() as $line) {
                $plan = $plans[$line->getPosition()] ?? null;
                if ($plan === null) {
                    continue;
                }
                $stock = $locked[$line->getItemId()];
                $movement = $this->stocks->applyMovement($stock, new StockMovementEntity(
                    $warehouseId,
                    $line->getItemId(),
                    $plan['direction'],
                    $plan['quantity'],
                    $plan['unit_cost'],
                    $plan['total_cost'],
                    $plan['balance_after'],
                    $plan['average_after'],
                    null,
                    $line->getId(),
                ), $companyId);
                foreach ($this->incidents->afterMovement($stock, $movement, $reference, $userId, $now) as $incident) {
                    $raised[(int) $incident->getId()] = $incident;
                }
                foreach ($afterMovement !== null ? $afterMovement($saved, $stock, $movement) : [] as $incident) {
                    $raised[(int) $incident->getId()] = $incident;
                }
            }

            return new LedgerResult(
                $this->documents->findById((int) $saved->getId(), $companyId) ?? $saved,
                array_values($raised),
            );
        });
    }

    /** The company's next number; two operations at once collide on the unique key and retry once. */
    private function persist(ItemDocumentEntity $draft): ItemDocumentEntity
    {
        for ($attempt = 0; ; $attempt++) {
            $draft->assignNumber($this->documents->nextNumber($draft->getCompanyId()));
            try {
                return $this->documents->create($draft);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 1) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Applies a planned movement to the running state of its item and returns what to persist.
     *
     * @param array{balance: Decimal, average: Decimal} $after
     * @param array{balance: Decimal, average: Decimal} $state
     * @return array<string, mixed>
     */
    private function step(int $direction, Decimal $quantity, Decimal $unitCost, Decimal $total, array $after, array &$state): array
    {
        $state = $after;

        return [
            'direction' => $direction,
            'quantity' => $quantity->toScale(self::QUANTITY_SCALE),
            'unit_cost' => $unitCost->toScale(self::COST_SCALE),
            'total_cost' => $total->toScale(self::QUANTITY_SCALE),
            'balance_after' => $after['balance']->toScale(self::QUANTITY_SCALE),
            'average_after' => $after['average']->toScale(self::COST_SCALE),
        ];
    }

    /**
     * @param array<int, ItemStockEntity> $locked
     * @return array<int, array{balance: Decimal, average: Decimal}>
     */
    private function states(array $locked): array
    {
        return array_map(fn (ItemStockEntity $stock) => [
            'balance' => Decimal::of($stock->getQuantity()),
            'average' => Decimal::of($stock->getAverageUnitCost()),
        ], $locked);
    }

    /** @return list<int> */
    private function itemIds(ItemDocumentEntity $document): array
    {
        return array_map(fn (ItemDocumentLineEntity $line) => $line->getItemId(), $document->getLines());
    }
}
