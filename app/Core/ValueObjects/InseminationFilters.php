<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * Filters of the inseminations of every AI order (semen S-B, read by the assistant and MCP). The
 * pregnancy outcome is not a filter: attributing a pregnancy to a straw is S-C.
 */
final readonly class InseminationFilters
{
    public function __construct(
        public ?int $itemId = null,
        public ?int $semenSireId = null,
        public ?int $semenLotId = null,
        public ?int $serviceOrderId = null,
        public ?int $batchId = null,
        public ?string $caravan = null,
        public ?string $from = null,
        public ?string $to = null,
        public bool $includeVoided = false,
        public int $page = 1,
        public int $perPage = 50,
    ) {
    }

    /** @param array<string, mixed> $query */
    public static function fromArray(array $query): self
    {
        $date = fn (mixed $v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
        $id = fn (string $key): ?int => isset($query[$key]) && (int) $query[$key] > 0 ? (int) $query[$key] : null;
        $caravan = isset($query['caravan']) ? trim((string) $query['caravan']) : '';

        return new self(
            $id('item_id'),
            $id('semen_sire_id'),
            $id('semen_lot_id'),
            $id('service_order_id'),
            $id('batch_id'),
            $caravan === '' ? null : $caravan,
            $date($query['from'] ?? null),
            $date($query['to'] ?? null),
            filter_var($query['include_voided'] ?? false, FILTER_VALIDATE_BOOLEAN),
            max(1, (int) ($query['page'] ?? 1)),
            min(200, max(1, (int) ($query['per_page'] ?? 50))),
        );
    }
}
