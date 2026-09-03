<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum PathogenCategory: string
{
    case VENEREAL = 'VENEREAL';
    case LOCOMOTOR = 'LOCOMOTOR';
    case SYSTEMIC = 'SYSTEMIC';
    case OCULAR = 'OCULAR';
}
