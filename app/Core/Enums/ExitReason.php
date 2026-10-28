<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Why animals leave the company. Only SALE in this delivery; an auction, own consumption or a loss
 * come later on the same order.
 */
enum ExitReason: string
{
    case SALE = 'SALE';

    public function label(): string
    {
        return match ($this) {
            self::SALE => 'Venta',
        };
    }

    /** A sale needs who buys (E6). */
    public function requiresBuyer(): bool
    {
        return $this === self::SALE;
    }
}
