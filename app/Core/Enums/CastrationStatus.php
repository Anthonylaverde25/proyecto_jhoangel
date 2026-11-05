<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum CastrationStatus: string
{
    case CASTRATED = 'CASTRATED';
    case INTACT = 'INTACT';
    case NOT_RECORDED = 'NOT_RECORDED';

    public function label(): string
    {
        return match ($this) {
            self::CASTRATED => 'Castrado',
            self::INTACT => 'Entero',
            self::NOT_RECORDED => 'Sin castración registrada',
        };
    }
}

