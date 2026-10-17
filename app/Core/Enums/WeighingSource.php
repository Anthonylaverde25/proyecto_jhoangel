<?php

declare(strict_types=1);

namespace App\Core\Enums;

/** The flow that wrote a caravan weight, for auditing where a number came from. */
enum WeighingSource: string
{
    case MANUAL = 'MANUAL';
    case WEIGHING_ORDER = 'WEIGHING_ORDER';
    case WEANING = 'WEANING';
    case ACTIVITY_CHANGE = 'ACTIVITY_CHANGE';
    case ENTRY = 'ENTRY';
    case BIRTH = 'BIRTH';
    case IMPORT = 'IMPORT';
}
