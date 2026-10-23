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
    case UNIDENTIFIED_SEMEN_LOT = 'UNIDENTIFIED_SEMEN_LOT';

    public function label(): string
    {
        return match ($this) {
            self::NEGATIVE_BALANCE => 'Saldo negativo',
            self::CRITICAL_STOCK => 'Stock crítico',
            self::COUNT_DISCREPANCY => 'Diferencia de conteo',
            self::UNIDENTIFIED_SEMEN_LOT => 'Identificar partida',
        };
    }

    public function resolvesItself(): bool
    {
        return $this === self::NEGATIVE_BALANCE || $this === self::CRITICAL_STOCK;
    }

    /** Closed only by identifying the lot of its straws (semen S-A, SU5), never by hand. */
    public function resolvesByIdentification(): bool
    {
        return $this === self::UNIDENTIFIED_SEMEN_LOT;
    }
}
