<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * Filters of an item's kardex. Dates filter by the declared date; the order is always the load
 * order (D4).
 */
final readonly class KardexFilters
{
    public function __construct(
        public ?int $warehouseId = null,
        public ?string $from = null,
        public ?string $to = null,
        public int $page = 1,
        public int $perPage = 50,
    ) {
    }

    /** @param array<string, mixed> $query */
    public static function fromArray(array $query): self
    {
        $date = fn (mixed $v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;

        return new self(
            isset($query['warehouse_id']) && (int) $query['warehouse_id'] > 0 ? (int) $query['warehouse_id'] : null,
            $date($query['from'] ?? null),
            $date($query['to'] ?? null),
            max(1, (int) ($query['page'] ?? 1)),
            min(200, max(1, (int) ($query['per_page'] ?? 50))),
        );
    }
}
