<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\ValueObjects\Decimal;

/**
 * How an outbound quantity splits (delivery C, model 2.4): the valued units leave first and only then
 * the ones still waiting for their invoice. Shared by the ledger and by the consumption preview, so an
 * estimate cannot drift from what gets recorded.
 */
final class StockIssueSplit
{
    /** The units of $quantity that leave without a cost yet. */
    public static function pending(Decimal $balance, Decimal $unvalued, Decimal $quantity): Decimal
    {
        $valued = self::max($balance->sub($unvalued), Decimal::zero());

        return self::min($quantity->sub(self::min($quantity, $valued)), $unvalued);
    }

    private static function min(Decimal $a, Decimal $b): Decimal
    {
        return $a->compare($b) <= 0 ? $a : $b;
    }

    private static function max(Decimal $a, Decimal $b): Decimal
    {
        return $a->compare($b) >= 0 ? $a : $b;
    }
}
