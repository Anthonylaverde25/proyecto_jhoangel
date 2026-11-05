<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\ValueObjects\ConsumptionSourceOption;
use App\Core\ValueObjects\Decimal;
use App\Core\ValueObjects\WarehouseBalance;

/**
 * Where a consumption can leave from. The warehouse asked for (the default one, unless the user named
 * another) goes first. When it covers the quantity there is nothing to decide. When it does not and no
 * other warehouse holds the item, it is recorded there with a negative balance. Otherwise the person
 * chooses: go negative, complete it from the others, or take it all from one that covers it.
 */
final class ConsumptionSourcePlanner
{
    public function __construct(
        private readonly WeightedAverageCost $costs,
    ) {
    }

    /**
     * @param list<WarehouseBalance> $balances the warehouse asked for must be among them
     * @return list<ConsumptionSourceOption> first the warehouse asked for; more than one means the person chooses
     */
    public function plan(array $balances, int $warehouseId, Decimal $quantity): array
    {
        $base = null;
        $others = [];
        foreach ($balances as $balance) {
            if ($balance->warehouseId === $warehouseId) {
                $base = $balance;
            } elseif ($balance->balance->isPositive()) {
                $others[] = $balance;
            }
        }
        if ($base === null) {
            throw new \InvalidArgumentException("Warehouse {$warehouseId} is not among the balances.");
        }

        $options = [$this->leaving(ConsumptionSourceOption::BASE, $base, $quantity)];
        if ($base->balance->compare($quantity) >= 0 || $others === []) {
            return $options;
        }

        $complete = $this->completing($base, $others, $quantity);
        if ($complete !== null) {
            $options[] = $complete;
        }
        foreach ($others as $other) {
            if ($other->balance->compare($quantity) >= 0) {
                $options[] = $this->leaving(ConsumptionSourceOption::OTHER, $other, $quantity);
            }
        }

        return $options;
    }

    private function leaving(string $code, WarehouseBalance $from, Decimal $quantity): ConsumptionSourceOption
    {
        return $this->option($code, $from, [], $from->balance, $from->unvalued, $from->average, $quantity);
    }

    /** @param list<WarehouseBalance> $others */
    private function completing(WarehouseBalance $base, array $others, Decimal $quantity): ?ConsumptionSourceOption
    {
        $held = $base->balance->isPositive() ? $base->balance : Decimal::zero();
        $missing = $quantity->sub($held);

        // The fullest warehouse first: as few transfers as possible.
        usort($others, fn (WarehouseBalance $a, WarehouseBalance $b) => $b->transferable()->compare($a->transferable()) ?: $a->warehouseId <=> $b->warehouseId);

        $balance = $base->balance;
        $average = $base->average;
        $transfers = [];
        foreach ($others as $other) {
            if (!$missing->isPositive()) {
                break;
            }
            $available = $other->transferable();
            if (!$available->isPositive()) {
                continue;
            }
            $moved = $available->compare($missing) <= 0 ? $available : $missing;
            $transfers[] = ['from' => $other, 'quantity' => $moved];
            $missing = $missing->sub($moved);

            $entered = $this->costs->enter($balance->sub($base->unvalued), $average, $moved, $other->average);
            $balance = $entered['balance']->add($base->unvalued);
            $average = $entered['average'];
        }

        return $transfers === [] ? null
            : $this->option(ConsumptionSourceOption::COMPLETE, $base, $transfers, $balance, $base->unvalued, $average, $quantity);
    }

    /** @param list<array{from: WarehouseBalance, quantity: Decimal}> $transfers */
    private function option(string $code, WarehouseBalance $warehouse, array $transfers, Decimal $balance, Decimal $unvalued, Decimal $average, Decimal $quantity): ConsumptionSourceOption
    {
        $pending = StockIssueSplit::pending($balance, $unvalued, $quantity);
        $known = $quantity->sub($pending);

        return new ConsumptionSourceOption(
            $code,
            $warehouse,
            $transfers,
            $balance->sub($quantity),
            $known->mul($average)->round(4),
            $pending,
            // An average of zero means no cost was ever declared: it is unknown, not free.
            !$known->isPositive() || $average->isPositive(),
        );
    }
}
