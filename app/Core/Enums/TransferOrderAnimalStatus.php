<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Where one line of the roll stands. SKIPPED is what an animal still PENDING becomes when the
 * order is closed incomplete: it was ordered and it did not travel.
 */
enum TransferOrderAnimalStatus: string
{
    case PENDING = 'PENDING';
    case MOVED = 'MOVED';
    case SKIPPED = 'SKIPPED';
}
