<?php

declare(strict_types=1);

namespace App\Core\Enums;

/** How the animals of a weighing order go over the scale. */
enum WeighingOrderMode: string
{
    /** One by one at the chute: a weight per caravan. */
    case INDIVIDUAL = 'INDIVIDUAL';

    /** The batch in groups on a cage or truck scale: heads, gross and tare per group. */
    case GROUP = 'GROUP';
}
