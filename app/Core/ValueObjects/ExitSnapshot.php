<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * Where an animal was the moment it left: its batch and that batch's activity, its category, and
 * whether it left pregnant (a pregnant cow is worth something else).
 */
final readonly class ExitSnapshot
{
    public function __construct(
        public ?int $fromBatchId,
        public ?int $activityId,
        public ?int $categoryId,
        public bool $wasPregnant,
        public ?string $fromBatchName = null,
        public ?string $activityName = null,
        public ?string $categoryName = null,
    ) {
    }
}
