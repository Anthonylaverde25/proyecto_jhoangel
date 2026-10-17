<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Enums\BreedingExclusionReason;

/**
 * The owner's breeding decision for one animal.
 */
final class BreedingEligibility
{
    public function __construct(
        public readonly bool $isEligible,
        public readonly ?BreedingExclusionReason $reason = null
    ) {
    }

    public function reasonLabel(): string
    {
        return $this->reason?->label() ?? 'sin motivo indicado';
    }
}
