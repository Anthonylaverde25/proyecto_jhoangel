<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

use App\Core\Entities\ItemDocumentEntity;
use App\Core\Entities\ItemDocumentLineEntity;
use App\Core\Entities\ItemStockEntity;
use App\Core\Entities\StockIncidentEntity;
use App\Core\Entities\StockMovementEntity;
use App\Core\Entities\UnvaluedIssueEntity;
use App\Core\Entities\UnvaluedLotEntity;
use App\Core\Enums\ItemDocumentType;
use App\Core\Exceptions\ItemDocumentException;
use App\Core\Exceptions\StaleCountException;
use App\Core\Interfaces\IItemDocumentRepository;
use App\Core\Interfaces\IItemStockRepository;
use App\Core\Interfaces\IUnvaluedLotRepository;
use App\Core\Services\WeightedAverageCost;
use App\Core\ValueObjects\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Every stock operation ends here (plan 5.2). In one transaction: lock the balance rows in item order
 * (DS7), value the lines at the locked average, insert the operation, append its movements, update
 * the balances and raise the notices. Any failure rolls everything back (I7).
 *
 * Units of an unvalued delivery note wait in lots without cost (delivery C, model 2): the average is
 * over the valued balance only, outbound lines take the valued units first, and the invoice values
 * the lots later.
 */
final class StockLedger
{
    private const QUANTITY_SCALE = 4;
    private const COST_SCALE = 6;

    /** PENDING lots locked during the current commit, by "warehouse:item". @var array<string, list<UnvaluedLotEntity>> */
    private array $pendingLots = [];

    public function __construct(
        private readonly IItemDocumentRepository $documents,
        private readonly IItemStockRepository $stocks,
        private readonly IUnvaluedLotRepository $lots,
        private readonly WeightedAverageCost $costs,
        private readonly StockIncidentRaiser $incidents,
    ) {
    }

    /**
     * PURCHASE, INITIAL and DELIVERY_RECEIPT. A line with a cost enters the valued stock at it; a line
     * without one (an unvalued delivery note) enters as pending and opens its lot (model 2.3).
     */
    public function receive(ItemDocumentEntity $draft, ?int $userId): LedgerResult
    {
        foreach ($draft->getLines() as $line) {
            if ($line->getUnitCostOrNull() === null) {
                $line->valuate(null, '0.0000', $line->getQuantity());
            }
        }

        return $this->commit($draft->getCompanyId(), $draft->getWarehouseId(), $this->itemIds($draft), $userId, function (array $locked) use ($draft) {
            $state = $this->states($locked);
            $plans = [];
            foreach ($draft->getLines() as $line) {
                $quantity = Decimal::of($line->getQuantity());
                $current = &$state[$line->getItemId()];
                if ($line->isFullyUnvalued()) {
                    $after = ['balance' => $current['balance']->add($quantity), 'average' => $current['average'], 'unvalued' => $current['unvalued']->add($quantity)];
                    $plans[$line->getPosition()] = $this->step(1, $quantity, null, Decimal::zero(), $after, $current, $quantity);
                } else {
                    $cost = Decimal::of($line->getUnitCost());
                    $after = $this->enterValued($current, $quantity, $cost);
                    $plans[$line->getPosition()] = $this->step(1, $quantity, $cost, Decimal::of($line->getTotalCost()), $after, $current);
                }
                unset($current);
            }

            return [$draft, $plans];
        }, afterPersist: function (ItemDocumentEntity $saved): void {
            foreach ($saved->getLines() as $line) {
                if ($line->isFullyUnvalued()) {
                    $this->lots->create(new UnvaluedLotEntity(
                        null,
                        $saved->getCompanyId(),
                        $saved->getWarehouseId(),
                        $line->getItemId(),
                        (int) $line->getFiscalDocumentLineId(),
                        (int) $line->getId(),
                        $line->getQuantity(),
                        $line->getQuantity(),
                    ));
                }
            }
        });
    }

    /** CONSUMPTION and RETURN_TO_PROVIDER: the valued part leaves at the average of the locked row (model 2.4). */
    public function issue(ItemDocumentEntity $draft, ?int $userId): LedgerResult
    {
        $taken = [];

        return $this->commit($draft->getCompanyId(), $draft->getWarehouseId(), $this->itemIds($draft), $userId, function (array $locked) use ($draft, &$taken) {
            $state = $this->states($locked);
            $plans = [];
            foreach ($draft->getLines() as $line) {
                $current = &$state[$line->getItemId()];
                [$plans[$line->getPosition()], $taken[$line->getPosition()]] = $this->leave($draft->getCompanyId(), $draft->getWarehouseId(), $line, Decimal::of($line->getQuantity()), $current);
                unset($current);
            }

            return [$draft, $plans];
        }, afterPersist: function (ItemDocumentEntity $saved) use (&$taken): void {
            $this->recordIssues($saved, $taken);
        });
    }

    /**
     * COUNT_ADJUSTMENT of one item in one warehouse (D18). The difference is taken against the locked
     * balance, which must still be the one the operator saw (DS6). Without difference the line stays,
     * as proof the item was counted, with no movement. A shortage leaves like a consumption (model 2.4).
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
        $taken = [];

        return $this->commit($companyId, $warehouseId, [$itemId], $userId, function (array $locked) use ($companyId, $warehouseId, $resolved, $itemId, $counted, $seen, $reason, $occurredAt, $idempotencyKey, $userId, &$system, &$taken) {
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
            if ($difference->isPositive()) {
                $plans[1] = $this->step(1, $quantity, $average, $total, $this->enterValued($current, $quantity, $average), $current);
            } elseif ($difference->isNegative()) {
                [$plans[1], $taken[1]] = $this->leave($companyId, $warehouseId, $line, $quantity, $current);
            }

            return [$draft, $plans];
        }, function (ItemDocumentEntity $saved, ItemStockEntity $stock, StockMovementEntity $movement) use ($counted, &$system, $userId): array {
            return [$this->incidents->countDiscrepancy($stock, $movement, (int) $saved->getNumber(), $counted->toScale(self::QUANTITY_SCALE), (string) $system, $userId, now()->toIso8601String())];
        }, function (ItemDocumentEntity $saved) use (&$taken): void {
            $this->recordIssues($saved, $taken);
        });
    }

    /**
     * VALUATION of an invoice converted from delivery notes (model 2.3, Q3). One line per lot that still
     * holds units and per non-recoverable tax; a lot whose units all left takes its cost only in its
     * issues. Null when nothing reaches the warehouse.
     *
     * @param list<ValuationEntry> $entries
     */
    public function valuate(int $companyId, int $warehouseId, int $fiscalDocumentId, string $occurredAt, array $entries, ?int $userId): ?LedgerResult
    {
        $at = now()->toIso8601String();
        $lines = [];
        $lotsByPosition = [];
        foreach ($entries as $entry) {
            $source = $entry->invoiceLine;
            if ($entry->lot !== null) {
                $remaining = Decimal::of($entry->lot->getRemainingQuantity());
                if (!$remaining->isPositive()) {
                    $this->settleLot($entry->lot, (string) $source->unitCost, (int) $source->id, $at, null);
                    continue;
                }
                $cost = Decimal::of((string) $source->unitCost);
                $quantity = $remaining;
                $total = $remaining->mul($cost)->round(self::QUANTITY_SCALE);
            } elseif ($entry->taxAmount !== null) {
                $quantity = Decimal::zero();
                $total = Decimal::of($entry->taxAmount);
                $cost = $total->div(Decimal::of($source->quantity))->round(self::COST_SCALE);
            } else {
                continue;
            }

            $position = count($lines) + 1;
            $lines[] = new ItemDocumentLineEntity(
                $position, $source->itemId, $source->inputQuantity, $source->inputUnitId, $source->inputPresentationId,
                $quantity->toScale(self::QUANTITY_SCALE),
                unitCost: $cost->toScale(self::COST_SCALE),
                totalCost: $total->toScale(self::QUANTITY_SCALE),
                fiscalDocumentLineId: $source->id,
            );
            if ($entry->lot !== null) {
                $lotsByPosition[$position] = $entry->lot;
            }
        }

        if ($lines === []) {
            return null;
        }

        $draft = new ItemDocumentEntity(
            null, $companyId, null, ItemDocumentType::VALUATION, $warehouseId, $occurredAt, $lines,
            fiscalDocumentId: $fiscalDocumentId, createdBy: $userId,
        );

        return $this->commit($companyId, $warehouseId, $this->itemIds($draft), $userId, function (array $locked) use ($draft, $lotsByPosition) {
            $state = $this->states($locked);
            $plans = [];
            foreach ($draft->getLines() as $line) {
                $current = &$state[$line->getItemId()];
                $valued = $current['balance']->sub($current['unvalued']);
                $quantity = Decimal::of($line->getQuantity());
                $cost = Decimal::of($line->getUnitCost());
                $total = Decimal::of($line->getTotalCost());

                if (isset($lotsByPosition[$line->getPosition()])) {
                    $entered = $this->costs->enter($valued, $current['average'], $quantity, $cost);
                    $after = ['balance' => $current['balance'], 'average' => $entered['average'], 'unvalued' => $current['unvalued']->sub($quantity)];
                    $plans[$line->getPosition()] = $this->step(0, $quantity, $cost, $total, $after, $current);
                } else {
                    $average = $this->costs->addValue($valued, $current['average'], $total);
                    if ($average === null) {
                        $line->valuate($line->getUnitCost(), '0.0000');
                        $line->leaveUnabsorbed($total->toScale(self::QUANTITY_SCALE));
                    } else {
                        $after = ['balance' => $current['balance'], 'average' => $average, 'unvalued' => $current['unvalued']];
                        $plans[$line->getPosition()] = $this->step(0, $quantity, $cost, $total, $after, $current);
                    }
                }
                unset($current);
            }

            return [$draft, $plans];
        }, afterPersist: function (ItemDocumentEntity $saved) use ($lotsByPosition, $at): void {
            foreach ($saved->getLines() as $line) {
                $lot = $lotsByPosition[$line->getPosition()] ?? null;
                if ($lot !== null) {
                    $this->settleLot($lot, $line->getUnitCost(), (int) $line->getFiscalDocumentLineId(), $at, $line->getId());
                }
            }
        });
    }

    /**
     * REVERSAL: replicates every line of the original; the movement goes the other way and exists only
     * where the original moved (model 2.8). Undoing an entry is refused when what remains would be worth
     * less than nothing (plan 4.7), an unvalued delivery note whose units already left (Q1) and a
     * valuation whose units already left.
     */
    public function reverse(ItemDocumentEntity $original, string $what, string $reason, string $occurredAt, ?string $idempotencyKey, ?int $userId): LedgerResult
    {
        $lines = [];
        foreach ($original->getLines() as $line) {
            $lines[$line->getPosition()] = new ItemDocumentLineEntity(
                $line->getPosition(),
                $line->getItemId(),
                $line->getInputQuantity(),
                $line->getInputUnitId(),
                $line->getInputPresentationId(),
                $line->getQuantity(),
                $line->getInputUnitPrice(),
                $line->getUnitCostOrNull(),
                $line->getTotalCost(),
                $line->getFiscalDocumentLineId(),
                $line->getCountedQuantity(),
                $line->getId(),
                unvaluedQuantity: $line->getUnvaluedQuantity(),
            );
        }

        $draft = new ItemDocumentEntity(
            null, $original->getCompanyId(), null, ItemDocumentType::REVERSAL, $original->getWarehouseId(), $occurredAt, array_values($lines),
            $original->getFiscalDocumentId(), $original->getBatchId(), $original->getHeadsTreated(), $original->getId(),
            $reason, null, $idempotencyKey, $userId,
        );

        $returned = [];

        return $this->commit($draft->getCompanyId(), $draft->getWarehouseId(), $this->itemIds($draft), $userId, function (array $locked) use ($original, $draft, $lines, $what, &$returned) {
            $state = $this->states($locked);
            $plans = [];
            foreach ($original->getLines() as $line) {
                $movement = $line->getMovement();
                if ($movement === null) {
                    continue;
                }

                $current = &$state[$line->getItemId()];
                $reversal = $lines[$line->getPosition()];
                $plans[$line->getPosition()] = match (true) {
                    $movement->direction > 0 => $this->undoEntry($original, $line, $movement, $what, $current),
                    $movement->direction < 0 => $this->undoExit($original, $line, $movement, $reversal, $current, $returned),
                    default => $this->undoValuation($line, $movement, $what, $current),
                };
                unset($current);
            }

            return [$draft, $plans];
        }, afterPersist: function (ItemDocumentEntity $saved) use (&$returned): void {
            $byPosition = [];
            foreach ($saved->getLines() as $line) {
                $byPosition[$line->getPosition()] = (int) $line->getId();
            }
            foreach ($returned as $position => $back) {
                foreach ($back['issues'] as $issue) {
                    $this->lots->markIssueReversed((int) $issue->id, $byPosition[$position], $issue->companyId);
                }
                foreach ($back['lots'] as $lot) {
                    $this->lots->save($lot);
                }
            }
        });
    }

    /** @param array{balance: Decimal, average: Decimal, unvalued: Decimal} $current */
    private function undoEntry(ItemDocumentEntity $original, ItemDocumentLineEntity $line, StockMovementEntity $movement, string $what, array &$current): array
    {
        $quantity = Decimal::of($movement->quantity);

        if ($line->isFullyUnvalued()) {
            $lot = $this->lots->lockByDeliveryNoteLines([(int) $line->getFiscalDocumentLineId()], $original->getCompanyId())[(int) $line->getFiscalDocumentLineId()] ?? null;
            if ($lot !== null && $lot->wasIssued()) {
                $numbers = implode(', ', array_map(fn (int $n) => "N.º {$n}", $this->lots->openIssueDocumentNumbers((int) $lot->getId(), $original->getCompanyId())));
                throw ItemDocumentException::on('domain', "No se puede anular {$what}: {$this->format($lot->issuedQuantity())} {$line->getItemUnitSymbol()} de {$line->getItemName()} ya salieron del almacén (movimientos {$numbers}). Anulá primero esos movimientos.");
            }
            $lot?->void();
            if ($lot !== null) {
                $this->lots->save($lot);
            }
            $after = ['balance' => $current['balance']->sub($quantity), 'average' => $current['average'], 'unvalued' => $current['unvalued']->sub($quantity)];

            return $this->step(-1, $quantity, null, Decimal::zero(), $after, $current, $quantity);
        }

        $cost = Decimal::of((string) $movement->unitCost);
        $valued = $current['balance']->sub($current['unvalued']);
        $undone = $this->costs->undoEntry($valued, $current['average'], $quantity, $cost);
        if ($undone === null) {
            throw ItemDocumentException::on('domain', "No se puede anular {$what}: lo que queda de {$line->getItemName()} vale menos que lo que saldría. Cargá primero un conteo o anulá los consumos posteriores.");
        }
        $after = ['balance' => $undone['balance']->add($current['unvalued']), 'average' => $undone['average'], 'unvalued' => $current['unvalued']];

        return $this->step(-1, $quantity, $cost, Decimal::of($movement->totalCost), $after, $current);
    }

    /**
     * An outbound line comes back: its valued part at its own cost, and each unit it took from a lot to
     * that lot — still pending, or valued at the lot's cost if its invoice arrived meanwhile.
     *
     * @param array{balance: Decimal, average: Decimal, unvalued: Decimal} $current
     * @param array<int, array{issues: list<UnvaluedIssueEntity>, lots: list<UnvaluedLotEntity>}> $returned
     */
    private function undoExit(ItemDocumentEntity $original, ItemDocumentLineEntity $line, StockMovementEntity $movement, ItemDocumentLineEntity $reversal, array &$current, array &$returned): array
    {
        $quantity = Decimal::of($movement->quantity);
        $known = $quantity->sub(Decimal::of($movement->unvaluedQuantity));
        $after = $current;
        $total = Decimal::zero();
        $valuedIn = Decimal::zero();
        $pendingIn = Decimal::zero();

        if ($known->isPositive()) {
            $after = $this->enterValued($after, $known, Decimal::of((string) $movement->unitCost));
            $total = $total->add(Decimal::of($movement->totalCost));
            $valuedIn = $valuedIn->add($known);
        }

        $issues = Decimal::of($movement->unvaluedQuantity)->isPositive() ? $this->lots->openIssuesOfLine((int) $line->getId(), $original->getCompanyId()) : [];
        $lots = $this->lots->lockByIds(array_values(array_unique(array_map(fn (UnvaluedIssueEntity $i) => $i->lotId, $issues))), $original->getCompanyId());
        foreach ($issues as $issue) {
            $lot = $lots[$issue->lotId];
            $back = Decimal::of($issue->quantity);
            if ($lot->isPending()) {
                $lot->giveBack($back);
                $pendingIn = $pendingIn->add($back);
                continue;
            }
            $cost = Decimal::of((string) $lot->getValuedUnitCost());
            $after = $this->enterValued($after, $back, $cost);
            $total = $total->add($back->mul($cost)->round(self::QUANTITY_SCALE));
            $valuedIn = $valuedIn->add($back);
        }
        $after['balance'] = $after['balance']->add($pendingIn);
        $after['unvalued'] = $after['unvalued']->add($pendingIn);
        $returned[$line->getPosition()] = ['issues' => $issues, 'lots' => array_values($lots)];

        $unitCost = $valuedIn->isPositive() ? $total->div($valuedIn)->round(self::COST_SCALE) : null;
        $reversal->valuate($unitCost?->toScale(self::COST_SCALE), $total->toScale(self::QUANTITY_SCALE), $pendingIn->toScale(self::QUANTITY_SCALE));

        return $this->step(1, $quantity, $unitCost, $total, $after, $current, $pendingIn);
    }

    /**
     * A valuation comes out: the lot's units wait again, or the tax leaves the average (model 2.8).
     * The lots themselves are put back by whoever voids the invoice.
     *
     * @param array{balance: Decimal, average: Decimal, unvalued: Decimal} $current
     */
    private function undoValuation(ItemDocumentLineEntity $line, StockMovementEntity $movement, string $what, array &$current): array
    {
        $quantity = Decimal::of($movement->quantity);
        $cost = Decimal::of((string) $movement->unitCost);
        $total = Decimal::of($movement->totalCost);
        $valued = $current['balance']->sub($current['unvalued']);

        if ($quantity->isPositive()) {
            if ($valued->compare($quantity) < 0) {
                $gone = $quantity->sub($this->max($valued, Decimal::zero()));
                throw ItemDocumentException::on('domain', "No se puede anular {$what}: {$this->format($gone->toScale(4))} de los {$this->format($quantity->toScale(4))} {$line->getItemUnitSymbol()} de {$line->getItemName()} que valorizó ya salieron del almacén. Anulá primero esos movimientos.");
            }
            $undone = $this->costs->undoEntry($valued, $current['average'], $quantity, $cost);
            $average = $undone === null ? null : $undone['average'];
            $unvalued = $current['unvalued']->add($quantity);
        } else {
            $average = $this->costs->removeValue($valued, $current['average'], $total);
            $unvalued = $current['unvalued'];
        }

        if ($average === null) {
            throw ItemDocumentException::on('domain', "No se puede anular {$what}: lo que queda de {$line->getItemName()} vale menos que lo que saldría. Cargá primero un conteo o anulá los consumos posteriores.");
        }

        return $this->step(0, $quantity, $cost, $total, ['balance' => $current['balance'], 'average' => $average, 'unvalued' => $unvalued], $current);
    }

    /**
     * model 2.4: valued units first, then the pending lots oldest first, the rest below zero at the
     * average. Values the line with what is known.
     *
     * @param array{balance: Decimal, average: Decimal, unvalued: Decimal} $current
     * @return array{0: array<string, mixed>, 1: list<array{0: UnvaluedLotEntity, 1: Decimal}>}
     */
    private function leave(int $companyId, int $warehouseId, ItemDocumentLineEntity $line, Decimal $quantity, array &$current): array
    {
        $average = $current['average'];
        $valued = $this->max($current['balance']->sub($current['unvalued']), Decimal::zero());
        $wanted = $this->min($quantity->sub($this->min($quantity, $valued)), $current['unvalued']);

        $from = [];
        $pending = Decimal::zero();
        if ($wanted->isPositive()) {
            foreach ($this->pendingLotsOf($warehouseId, $line->getItemId(), $companyId) as $lot) {
                if ($pending->compare($wanted) >= 0) {
                    break;
                }
                $got = $lot->take($wanted->sub($pending));
                if ($got->isPositive()) {
                    $from[] = [$lot, $got];
                    $pending = $pending->add($got);
                }
            }
        }

        $known = $quantity->sub($pending);
        $unitCost = $known->isPositive() ? $average : null;
        $total = $known->mul($average)->round(self::QUANTITY_SCALE);
        $line->valuate($unitCost?->toScale(self::COST_SCALE), $total->toScale(self::QUANTITY_SCALE), $pending->toScale(self::QUANTITY_SCALE));

        $after = ['balance' => $current['balance']->sub($quantity), 'average' => $average, 'unvalued' => $current['unvalued']->sub($pending)];

        return [$this->step(-1, $quantity, $unitCost, $total, $after, $current, $pending), $from];
    }

    /** @param array<int, list<array{0: UnvaluedLotEntity, 1: Decimal}>> $taken by line position */
    private function recordIssues(ItemDocumentEntity $saved, array $taken): void
    {
        $issues = [];
        $lots = [];
        foreach ($saved->getLines() as $line) {
            foreach ($taken[$line->getPosition()] ?? [] as [$lot, $quantity]) {
                $issues[] = new UnvaluedIssueEntity(null, $saved->getCompanyId(), (int) $lot->getId(), (int) $line->getId(), $quantity->toScale(self::QUANTITY_SCALE));
                $lots[(int) $lot->getId()] = $lot;
            }
        }
        foreach ($lots as $lot) {
            $this->lots->save($lot);
        }
        $this->lots->createIssues($issues);
    }

    /** The lot takes the invoice's cost, and so do the units that already left it (model 2.3). */
    private function settleLot(UnvaluedLotEntity $lot, string $unitCost, int $invoiceLineId, string $at, ?int $valuationLineId): void
    {
        $lot->value($unitCost, $invoiceLineId, $at);
        if ($valuationLineId !== null) {
            $lot->attachValuationLine($valuationLineId);
        }
        $this->lots->save($lot);
        $this->lots->setIssueCosts((int) $lot->getId(), $unitCost, $lot->getCompanyId());
    }

    /** @return list<UnvaluedLotEntity> */
    private function pendingLotsOf(int $warehouseId, int $itemId, int $companyId): array
    {
        return $this->pendingLots["{$warehouseId}:{$itemId}"] ??= $this->lots->lockPending($warehouseId, $itemId, $companyId);
    }

    /**
     * An entry at its own cost into the valued balance (model 2.3); the pending units stay aside.
     *
     * @param array{balance: Decimal, average: Decimal, unvalued: Decimal} $state
     * @return array{balance: Decimal, average: Decimal, unvalued: Decimal}
     */
    private function enterValued(array $state, Decimal $quantity, Decimal $unitCost): array
    {
        $entered = $this->costs->enter($state['balance']->sub($state['unvalued']), $state['average'], $quantity, $unitCost);

        return ['balance' => $entered['balance']->add($state['unvalued']), 'average' => $entered['average'], 'unvalued' => $state['unvalued']];
    }

    /**
     * @param list<int> $itemIds
     * @param callable(array<int, ItemStockEntity>): array{0: ItemDocumentEntity, 1: array<int, array<string, mixed>>} $prepare
     * @param (callable(ItemDocumentEntity, ItemStockEntity, StockMovementEntity): list<StockIncidentEntity>)|null $afterMovement
     * @param (callable(ItemDocumentEntity): void)|null $afterPersist lots and issues, once the lines have ids
     */
    private function commit(int $companyId, int $warehouseId, array $itemIds, ?int $userId, callable $prepare, ?callable $afterMovement = null, ?callable $afterPersist = null): LedgerResult
    {
        return DB::transaction(function () use ($companyId, $warehouseId, $itemIds, $userId, $prepare, $afterMovement, $afterPersist) {
            $itemIds = array_values(array_unique($itemIds));
            sort($itemIds);
            $locked = [];
            foreach ($itemIds as $itemId) {
                $locked[$itemId] = $this->stocks->lockForMovement($warehouseId, $itemId, $companyId);
            }
            $this->pendingLots = [];

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
                    $plan['unvalued_quantity'],
                    $plan['unvalued_after'],
                ), $companyId);

                foreach ($this->incidents->afterMovement($stock, $movement, $reference, $userId, $now) as $incident) {
                    $raised[(int) $incident->getId()] = $incident;
                }
                foreach ($afterMovement !== null ? $afterMovement($saved, $stock, $movement) : [] as $incident) {
                    $raised[(int) $incident->getId()] = $incident;
                }
            }

            if ($afterPersist !== null) {
                $afterPersist($saved);
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
     * @param array{balance: Decimal, average: Decimal, unvalued: Decimal} $after
     * @param array{balance: Decimal, average: Decimal, unvalued: Decimal} $state
     * @return array<string, mixed>
     */
    private function step(int $direction, Decimal $quantity, ?Decimal $unitCost, Decimal $total, array $after, array &$state, ?Decimal $unvalued = null): array
    {
        $state = $after;

        return [
            'direction' => $direction,
            'quantity' => $quantity->toScale(self::QUANTITY_SCALE),
            'unit_cost' => $unitCost?->toScale(self::COST_SCALE),
            'total_cost' => $total->toScale(self::QUANTITY_SCALE),
            'balance_after' => $after['balance']->toScale(self::QUANTITY_SCALE),
            'average_after' => $after['average']->toScale(self::COST_SCALE),
            'unvalued_quantity' => ($unvalued ?? Decimal::zero())->toScale(self::QUANTITY_SCALE),
            'unvalued_after' => $after['unvalued']->toScale(self::QUANTITY_SCALE),
        ];
    }

    /**
     * @param array<int, ItemStockEntity> $locked
     * @return array<int, array{balance: Decimal, average: Decimal, unvalued: Decimal}>
     */
    private function states(array $locked): array
    {
        return array_map(fn (ItemStockEntity $stock) => [
            'balance' => Decimal::of($stock->getQuantity()),
            'average' => Decimal::of($stock->getAverageUnitCost()),
            'unvalued' => Decimal::of($stock->getUnvaluedQuantity()),
        ], $locked);
    }

    /** @return list<int> */
    private function itemIds(ItemDocumentEntity $document): array
    {
        return array_map(fn (ItemDocumentLineEntity $line) => $line->getItemId(), $document->getLines());
    }

    private function min(Decimal $a, Decimal $b): Decimal
    {
        return $a->compare($b) <= 0 ? $a : $b;
    }

    private function max(Decimal $a, Decimal $b): Decimal
    {
        return $a->compare($b) >= 0 ? $a : $b;
    }

    /** 3.0000 → "3", 2.5000 → "2,5". */
    private function format(string $quantity): string
    {
        $trimmed = rtrim(rtrim($quantity, '0'), '.');

        return str_replace('.', ',', $trimmed === '' ? '0' : $trimmed);
    }
}
