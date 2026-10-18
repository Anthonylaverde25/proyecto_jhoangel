<?php

declare(strict_types=1);

namespace App\Core\Enums;

/** Units in from an unvalued delivery note (delivery C, model 2.2): waiting for, or valued by, their invoice. */
enum UnvaluedLotStatus: string
{
    case PENDING = 'PENDING';
    case VALUED = 'VALUED';
    case VOIDED = 'VOIDED';
}
