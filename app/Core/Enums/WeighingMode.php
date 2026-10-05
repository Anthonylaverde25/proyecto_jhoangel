<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * How the animals of an ING-03 sheet are weighed: each one at the scale (a weight per line), or
 * the arrival as a whole (one average weight in the header, assigned to every caravan that came).
 */
enum WeighingMode: string
{
    case INDIVIDUAL = 'INDIVIDUAL';
    case AVERAGE = 'AVERAGE';

    public function label(): string
    {
        return match ($this) {
            self::INDIVIDUAL => 'Peso individual',
            self::AVERAGE => 'Peso promedio',
        };
    }
}
