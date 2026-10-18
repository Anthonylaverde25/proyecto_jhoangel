<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Non-blocking stock notices (D5, D19). The first two resolve themselves when the balance recovers;
 * a count discrepancy is explained by hand.
 */
enum StockIncidentType: string
{
    case NEGATIVE_BALANCE = 'NEGATIVE_BALANCE';
    case CRITICAL_STOCK = 'CRITICAL_STOCK';
    case COUNT_DISCREPANCY = 'COUNT_DISCREPANCY';

    public function label(): string
    {
        return match ($this) {
            self::NEGATIVE_BALANCE => 'Saldo negativo',
            self::CRITICAL_STOCK => 'Stock crítico',
            self::COUNT_DISCREPANCY => 'Diferencia de conteo',
        };
    }

    public function resolvesItself(): bool
    {
        return $this !== self::COUNT_DISCREPANCY;
    }
}
