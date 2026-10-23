<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum StrawSize: string
{
    case MICRO_0_1 = 'MICRO_0_1';
    case MINI_0_25 = 'MINI_0_25';
    case MEDIUM_0_5 = 'MEDIUM_0_5';

    public function label(): string
    {
        return match ($this) {
            self::MICRO_0_1 => 'Micro 0,1 ml',
            self::MINI_0_25 => 'Mini 0,25 ml',
            self::MEDIUM_0_5 => 'Media 0,5 ml',
        };
    }
}
