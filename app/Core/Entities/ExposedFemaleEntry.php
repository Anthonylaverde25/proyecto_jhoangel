<?php

declare(strict_types=1);

namespace App\Core\Entities;

use DateTimeImmutable;

/**
 * One female of a sanitary exposure alert, with where she is now and whether she was reviewed.
 */
final readonly class ExposedFemaleEntry
{
    public function __construct(
        public int $femaleId,
        public string $femaleTag,
        public ?int $currentBatchId,
        public ?string $currentBatchName,
        public bool $isPregnant,
        public int $serviceOrderId,
        public string $serviceOrderCode,
        public DateTimeImmutable $overlapFrom,
        public ?DateTimeImmutable $overlapUntil,
        public ?DateTimeImmutable $reviewedAt,
        public ?string $reviewNote
    ) {
    }
}
