<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum LabSampleStatus: string
{
    case PENDING_RESULTS = 'PENDING_RESULTS';
    case NEGATIVE_CLEARED = 'NEGATIVE_CLEARED';
    case POSITIVE_DETECTED = 'POSITIVE_DETECTED';
}
