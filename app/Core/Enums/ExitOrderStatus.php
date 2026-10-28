<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Lifecycle of an exit order (egreso plan v5, 01 §3). The status follows the roll: with nothing
 * pending, every line out means EXECUTED and any skipped line means CLOSED_INCOMPLETE.
 */
enum ExitOrderStatus: string
{
    case ISSUED = 'ISSUED';
    case PARTIAL = 'PARTIAL';
    case EXECUTED = 'EXECUTED';
    case CLOSED_INCOMPLETE = 'CLOSED_INCOMPLETE';
    case CANCELLED = 'CANCELLED';

    /** Still commits its pending animals and can be executed against. */
    public function isOpen(): bool
    {
        return $this === self::ISSUED || $this === self::PARTIAL;
    }

    public function label(): string
    {
        return match ($this) {
            self::ISSUED => 'Emitida',
            self::PARTIAL => 'Parcial',
            self::EXECUTED => 'Ejecutada',
            self::CLOSED_INCOMPLETE => 'Cerrada incompleta',
            self::CANCELLED => 'Anulada',
        };
    }
}
