<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * How an exit order was born: issued before the trip (PLANNED) or registered after it happened,
 * already executed (REGISTERED). Declared, never inferred from dates.
 */
enum ExitOrderKind: string
{
    case PLANNED = 'PLANNED';
    case REGISTERED = 'REGISTERED';

    public function label(): string
    {
        return match ($this) {
            self::PLANNED => 'Emitida',
            self::REGISTERED => 'Registrada',
        };
    }
}
