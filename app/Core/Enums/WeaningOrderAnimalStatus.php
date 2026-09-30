<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Where one calf of a weaning order stands. SKIPPED is what a calf still PENDING becomes when the
 * order is closed incomplete or cancelled: it was ordered and it was not weaned with this order.
 */
enum WeaningOrderAnimalStatus: string
{
    case PENDING = 'PENDING';
    case WEANED = 'WEANED';
    case SKIPPED = 'SKIPPED';
}
