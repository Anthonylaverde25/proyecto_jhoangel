<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Enums\ItemBehavior;

/**
 * Filters of the item catalog list.
 */
final readonly class ItemFilters
{
    public function __construct(
        public ?ItemBehavior $behavior = null,
        /** Includes the category's descendants. */
        public ?int $categoryId = null,
        /** Matches code or name. */
        public ?string $search = null,
        public bool $includeInactive = false,
    ) {
    }

    /** @param array<string, mixed> $query */
    public static function fromArray(array $query): self
    {
        $search = isset($query['search']) ? trim((string) $query['search']) : '';

        return new self(
            isset($query['behavior']) ? ItemBehavior::tryFrom((string) $query['behavior']) : null,
            isset($query['category_id']) && (int) $query['category_id'] > 0 ? (int) $query['category_id'] : null,
            $search === '' ? null : $search,
            filter_var($query['include_inactive'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }
}
