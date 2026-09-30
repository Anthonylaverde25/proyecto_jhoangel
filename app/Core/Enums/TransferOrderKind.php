<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Whether the order was planned before the movement or registered after the movement had
 * already happened in the field. Declared by whoever loads it, never inferred from dates.
 */
enum TransferOrderKind: string
{
    case PLANNED = 'PLANNED';
    case REGISTERED = 'REGISTERED';

    public function label(): string
    {
        return match ($this) {
            self::PLANNED => 'Planificada',
            self::REGISTERED => 'Registrada',
        };
    }
}
