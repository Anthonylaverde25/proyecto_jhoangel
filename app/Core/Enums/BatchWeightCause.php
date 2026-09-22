<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Why an aggregate weight of a batch was recomputed.
 *
 * A weighing measures the same set of animals again, while a movement changes the set
 * itself. The batch average is a statistic over that set: when the set changes, the
 * statistic moves without any animal having gained or lost a gram. Recording both as
 * the same kind of event makes a compositional jump indistinguishable from a loss of
 * weight, which is how an emptied batch ends up looking like a herd that wasted away.
 */
enum BatchWeightCause: string
{
    /** Opening of a batch or of a production stage. */
    case INITIAL = 'INITIAL';

    /** Genuine measurement of the same set, or the closing snapshot before a movement. */
    case CONTROL = 'CONTROL';

    /** Animals entered the batch: the set grew. */
    case MOVEMENT_IN = 'MOVEMENT_IN';

    /** Animals left the batch: the set shrank. */
    case MOVEMENT_OUT = 'MOVEMENT_OUT';

    public function changesComposition(): bool
    {
        return $this === self::MOVEMENT_IN || $this === self::MOVEMENT_OUT;
    }
}
