<?php

declare(strict_types=1);

namespace App\Core\Enums;

/** What the operator wrote down for a batch weighing. */
enum BatchWeighingEntry: string
{
    /** Each cage or truck load: heads and kilos. */
    case LOADS = 'LOADS';

    /** The average per head already worked out (by the scale or by hand), and the heads weighed. */
    case AVERAGE = 'AVERAGE';
}
