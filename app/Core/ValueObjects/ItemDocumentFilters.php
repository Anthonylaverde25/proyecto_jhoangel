<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Enums\ItemDocumentType;

/**
 * Filters of the stock-operation list.
 */
final readonly class ItemDocumentFilters
{
    public function __construct(
        public ?ItemDocumentType $type = null,
        public ?int $itemId = null,
        public ?int $batchId = null,
        public ?string $from = null,
        public ?string $to = null,
        public int $page = 1,
        public int $perPage = 25,
        public bool $transfers = false,  // both halves of every transfer (delivery D)
    ) {
    }

    /** @param array<string, mixed> $query */
    public static function fromArray(array $query): self
    {
        $date = fn (mixed $v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
        $id = fn (mixed $v): ?int => is_numeric($v) && (int) $v > 0 ? (int) $v : null;

        return new self(
            isset($query['type']) ? ItemDocumentType::tryFrom((string) $query['type']) : null,
            $id($query['item_id'] ?? null),
            $id($query['batch_id'] ?? null),
            $date($query['from'] ?? null),
            $date($query['to'] ?? null),
            max(1, (int) ($query['page'] ?? 1)),
            min(100, max(1, (int) ($query['per_page'] ?? 25))),
            ($query['type'] ?? null) === 'TRANSFER',
        );
    }
}
