<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\ValueObjects\Decimal;

/**
 * Weighted average cost per (warehouse, item), logical schema 5.4. Pure: takes the balance and
 * average before a movement and gives them after it. Quantities are always positive; the caller
 * says whether the movement enters or leaves.
 */
final class WeightedAverageCost
{
    public const COST_SCALE = 6;

    /**
     * An entry at its own cost. With nothing (or less than nothing) in stock the entry sets the
     * average: dividing by zero or by a negative balance makes no sense.
     *
     * @return array{balance: Decimal, average: Decimal}
     */
    public function enter(Decimal $balance, Decimal $average, Decimal $quantity, Decimal $unitCost): array
    {
        $after = $balance->add($quantity);
        if (!$balance->isPositive() || !$after->isPositive()) {
            return ['balance' => $after, 'average' => $unitCost->round(self::COST_SCALE)];
        }

        $value = $balance->mul($average)->add($quantity->mul($unitCost));

        return ['balance' => $after, 'average' => $value->div($after)->round(self::COST_SCALE)];
    }

    /**
     * An exit at the current average, which does not change.
     *
     * @return array{balance: Decimal, average: Decimal}
     */
    public function leave(Decimal $balance, Decimal $average, Decimal $quantity): array
    {
        return ['balance' => $balance->sub($quantity), 'average' => $average];
    }

    /**
     * Undoing an entry takes it out at its original cost. Null when what remains would be worth less
     * than nothing: the only hard block of the module (plan 4.7).
     *
     * @return array{balance: Decimal, average: Decimal}|null
     */
    public function undoEntry(Decimal $balance, Decimal $average, Decimal $quantity, Decimal $unitCost): ?array
    {
        $after = $balance->sub($quantity);
        if (!$after->isPositive()) {
            return ['balance' => $after, 'average' => $average];
        }

        $value = $balance->mul($average)->sub($quantity->mul($unitCost));
        if ($value->isNegative()) {
            return null;
        }

        return ['balance' => $after, 'average' => $value->div($after)->round(self::COST_SCALE)];
    }
}
