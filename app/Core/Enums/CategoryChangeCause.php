<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum CategoryChangeCause: string
{
    case CASTRATION = 'CASTRATION';
    case CORRECTION = 'CORRECTION';
    case ABORTION_AS_CALVING = 'ABORTION_AS_CALVING';
    case STILLBIRTH = 'STILLBIRTH';

    public function label(): string
    {
        return match ($this) {
            self::CASTRATION => 'Castración',
            self::CORRECTION => 'Corrección',
            self::ABORTION_AS_CALVING => 'Aborto declarado como parto',
            self::STILLBIRTH => 'Nació muerto',
        };
    }
}

