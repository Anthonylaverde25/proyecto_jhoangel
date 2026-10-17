<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * How the kilos were obtained. An AVERAGE weight is the mean of a group assigned to each of its
 * animals: true for the mass of the batch, meaningless for ranking one animal against another.
 */
enum WeighingMethod: string
{
    /** Chute scale, one animal at a time. */
    case INDIVIDUAL = 'INDIVIDUAL';

    /** Group mean (cage, truck scale) assigned to each animal of the group. */
    case AVERAGE = 'AVERAGE';

    /** Hanging or spring scale carried to the paddock, typically at birth. */
    case PORTABLE_FIELD = 'PORTABLE_FIELD';

    /** Thoracic tape or visual estimate. */
    case ESTIMATED = 'ESTIMATED';

    /** Whether the number belongs to this animal alone. */
    public function isIndividualMeasurement(): bool
    {
        return $this === self::INDIVIDUAL || $this === self::PORTABLE_FIELD;
    }

    public static function fromWeighingMode(WeighingMode $mode): self
    {
        return $mode === WeighingMode::AVERAGE ? self::AVERAGE : self::INDIVIDUAL;
    }
}
