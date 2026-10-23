<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum SemenPreservation: string
{
    case FROZEN = 'FROZEN';
    case FRESH = 'FRESH';

    public function label(): string
    {
        return match ($this) {
            self::FROZEN => 'Congelado',
            self::FRESH => 'Fresco',
        };
    }
}
