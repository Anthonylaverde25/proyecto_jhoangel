<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * Filters of the animals that already left, across exit orders. Dates are those each animal left
 * on, not the planned date of its order; batch, activity and category are its exit snapshot.
 */
final readonly class ExitedAnimalCriteria
{
    public function __construct(
        public int $companyId,
        public ?string $from = null,
        public ?string $to = null,
        public ?int $exitOrderId = null,
        public ?int $buyerPartnerId = null,
        public ?int $categoryId = null,
        public ?int $activityId = null,
        public ?int $fromBatchId = null,
    ) {
    }
}
