<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * How a service order was born: issued in the system (ISSUED) or registered after the service ended
 * in the field (REGISTERED). Declared, never inferred from dates.
 */
enum ServiceOrderKind: string
{
    case ISSUED = 'ISSUED';
    case REGISTERED = 'REGISTERED';

    public function label(): string
    {
        return match ($this) {
            self::ISSUED => 'Emitida',
            self::REGISTERED => 'Registrada',
        };
    }
}
