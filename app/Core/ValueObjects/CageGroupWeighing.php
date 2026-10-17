<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Exceptions\WeighingOrderDomainException;

/**
 * One load of a cage or truck scale: how many head went on, what the scale read, and its tare.
 * Only the net mass and the head count are facts; the average is derived from them.
 */
final readonly class CageGroupWeighing
{
    public function __construct(
        public int $sequence,
        public int $headCount,
        public float $grossWeight,
        public float $tareWeight = 0.0,
    ) {
        if ($headCount < 1) {
            throw WeighingOrderDomainException::domainError("Jaula {$sequence}: indicá cuántas cabezas se pesaron.", 'GROUP_WITHOUT_HEADS');
        }

        if ($tareWeight < 0) {
            throw WeighingOrderDomainException::domainError("Jaula {$sequence}: la tara no puede ser negativa.", 'GROUP_NEGATIVE_TARE');
        }

        if ($grossWeight <= $tareWeight) {
            throw WeighingOrderDomainException::domainError(
                "Jaula {$sequence}: el bruto ({$grossWeight} kg) tiene que ser mayor que la tara ({$tareWeight} kg).",
                'GROUP_GROSS_NOT_ABOVE_TARE'
            );
        }
    }

    public function netWeight(): float
    {
        return $this->grossWeight - $this->tareWeight;
    }

    public function averageWeight(): float
    {
        return $this->netWeight() / $this->headCount;
    }
}
