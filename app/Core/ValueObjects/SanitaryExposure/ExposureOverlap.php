<?php

declare(strict_types=1);

namespace App\Core\ValueObjects\SanitaryExposure;

use DateTimeImmutable;

/**
 * A female that shared a service with the bull inside the risk window; $until null means they
 * were still together when the overlap was computed.
 */
final readonly class ExposureOverlap
{
    public function __construct(
        public int $serviceOrderId,
        public int $femaleId,
        public DateTimeImmutable $from,
        public ?DateTimeImmutable $until
    ) {
    }
}
