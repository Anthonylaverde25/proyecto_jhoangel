<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Gut fill at the moment of weighing. Water and rumen content are 10 to 20 % of live weight, so
 * two weights are only comparable on the same basis. The measured weight is never altered: the
 * condition is declared and any normalisation happens when the weight is read.
 */
enum ShrunkCondition: string
{
    /** Straight from the paddock. */
    case FULL_WEIGHT = 'FULL_WEIGHT';

    /** Penned 12 to 16 h without water or feed. */
    case SHRUNK_12H = 'SHRUNK_12H';

    /** Weighed full, with a declared percentage to discount (shrink_pct). */
    case CALCULATED = 'CALCULATED';

    public function requiresShrinkPct(): bool
    {
        return $this === self::CALCULATED;
    }
}
