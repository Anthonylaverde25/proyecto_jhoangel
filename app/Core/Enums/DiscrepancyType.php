<?php

declare(strict_types=1);

namespace App\Core\Enums;

/** A difference between a delivery note and the invoice it went into (DC7). */
enum DiscrepancyType: string
{
    case QUANTITY = 'QUANTITY';
    case PRICE = 'PRICE';
    case MISSING_LINE = 'MISSING_LINE';
    case EXTRA_LINE = 'EXTRA_LINE';
    case TOTAL = 'TOTAL';

    public function label(): string
    {
        return match ($this) {
            self::QUANTITY => 'Cantidad distinta',
            self::PRICE => 'Precio distinto',
            self::MISSING_LINE => 'Renglón sin facturar',
            self::EXTRA_LINE => 'Renglón de más',
            self::TOTAL => 'Total distinto',
        };
    }
}
