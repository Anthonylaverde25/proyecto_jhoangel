<?php

declare(strict_types=1);

namespace App\Application\DTOs\Activities;

final readonly class ActivityConfigItemDTO
{
    public function __construct(
        public int $activityId,
        public bool $isEnabled,
        public bool $isInitial,
        public bool $isFinal,
        public int $sortOrder
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['activity_id'] ?? 0),
            (bool) ($data['is_enabled'] ?? false),
            (bool) ($data['is_initial'] ?? false),
            (bool) ($data['is_final'] ?? false),
            (int) ($data['sort_order'] ?? 1)
        );
    }
}
