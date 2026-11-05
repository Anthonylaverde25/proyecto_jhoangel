<?php

declare(strict_types=1);

namespace App\Core\Enums;

/** What the operator wrote down for a batch weighing. */
enum BatchWeighingEntry: string
{
    /** Each cage or truck load: its category, heads and kilos. */
    case LOADS = 'LOADS';

    /** One average per head for each category weighed: one figure never speaks for a cow and a calf. */
    case BY_CATEGORY = 'BY_CATEGORY';

    /**
     * One average for the whole batch. No longer accepted, because it falsified a mixed batch;
     * kept so the orders already registered that way can still be read.
     */
    case AVERAGE = 'AVERAGE';
}
