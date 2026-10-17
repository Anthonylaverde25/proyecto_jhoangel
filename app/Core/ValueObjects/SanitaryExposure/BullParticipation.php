<?php

declare(strict_types=1);

namespace App\Core\ValueObjects\SanitaryExposure;

use DateTimeImmutable;

/**
 * The period a bull spent with the females of one service order.
 */
final readonly class BullParticipation
{
    public function __construct(
        public int $serviceOrderId,
        public bool $isControlledService,
        public DateTimeImmutable $joinedAt,
        public ?DateTimeImmutable $retiredAt
    ) {
    }
}
