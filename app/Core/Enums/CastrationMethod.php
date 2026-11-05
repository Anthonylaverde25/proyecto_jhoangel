<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum CastrationMethod: string
{
    case SURGICAL = 'SURGICAL';
    case BAND = 'BAND';
    case BURDIZZO = 'BURDIZZO';

    public function label(): string
    {
        return match ($this) {
            self::SURGICAL => 'Quirúrgica',
            self::BAND => 'Goma / elastrador',
            self::BURDIZZO => 'Pinza Burdizzo',
        };
    }
}

