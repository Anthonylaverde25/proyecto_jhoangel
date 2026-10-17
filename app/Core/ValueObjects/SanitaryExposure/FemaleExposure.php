<?php

declare(strict_types=1);

namespace App\Core\ValueObjects\SanitaryExposure;

use DateTimeImmutable;

/**
 * The exposure window of one female in one service order.
 */
final readonly class FemaleExposure
{
    public function __construct(
        public int $serviceOrderId,
        public int $femaleId,
        public DateTimeImmutable $exposedFrom,
        public ?DateTimeImmutable $exposedUntil,
        public ?int $assignedMaleId
    ) {
    }
}
