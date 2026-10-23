<?php

declare(strict_types=1);

namespace App\Core\Enums;

/** What a semen item is (semen S-A, plan 4.2): one item per sire and type. */
enum SemenType: string
{
    case CONVENTIONAL = 'CONVENTIONAL';
    case SEXED_FEMALE = 'SEXED_FEMALE';
    case SEXED_MALE = 'SEXED_MALE';

    public function label(): string
    {
        return match ($this) {
            self::CONVENTIONAL => 'Convencional',
            self::SEXED_FEMALE => 'Sexado hembra',
            self::SEXED_MALE => 'Sexado macho',
        };
    }
}
