<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum AnimalCategory: string
{
    case NOVILLITO = 'novillito';
    case NOVILLO = 'novillo';
    case VAQUILLONA = 'vaquillona';
    case VACA = 'vaca';
    case VACA_VACIA = 'vaca_vacia';
    case TERNERO = 'ternero';
    case TORO = 'toro';
}
