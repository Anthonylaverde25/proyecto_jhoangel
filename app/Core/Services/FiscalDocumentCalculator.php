<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\ValueObjects\Decimal;

/**
 * Amounts of a provider-document line (plan 4.5). Amounts keep 4 decimals, unit costs 6 (DS4); the
 * line amount is the paper's, so the totals match the invoice (DS5).
 */
final class FiscalDocumentCalculator
{
    public const AMOUNT_SCALE = 4;
    public const UNIT_COST_SCALE = 6;

    public function lineAmount(Decimal $inputQuantity, Decimal $unitPrice): Decimal
    {
        return $inputQuantity->mul($unitPrice)->round(self::AMOUNT_SCALE);
    }

    /** A percentage tax on a base: VAT 21 % of 120.000 = 25.200. */
    public function percentage(Decimal $base, Decimal $rate): Decimal
    {
        return $base->mul($rate)->div(Decimal::of('100'))->round(self::AMOUNT_SCALE);
    }

    /** Cost per unit of the item: (amount + non-recoverable taxes) / quantity in the item's unit. */
    public function unitCost(Decimal $cost, Decimal $quantity): Decimal
    {
        return $cost->div($quantity)->round(self::UNIT_COST_SCALE);
    }
}
