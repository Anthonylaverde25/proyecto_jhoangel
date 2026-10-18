<?php

declare(strict_types=1);

namespace App\Core\Enums;

/** Closed by hand with the provider (DC8), or cancelled when its invoice is voided. */
enum DiscrepancyStatus: string
{
    case OPEN = 'OPEN';
    case RESOLVED = 'RESOLVED';
    case CANCELLED = 'CANCELLED';
}
