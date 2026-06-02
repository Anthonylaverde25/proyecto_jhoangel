<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum ServiceType: string
{
    case SINGLE = 'single';
    case ROTATION = 'rotation';
    case MULTI = 'multi';
}
