<?php

declare(strict_types=1);

namespace App\Application\DTOs\Stock;

/**
 * Initial stock or a consumption. A consumption may be charged to a batch, with the heads that
 * received it (D22). Without a warehouse, the company's default (DD6).
 */
final readonly class StockOperationDTO
{
    /** @param list<StockLineDTO> $lines */
    public function __construct(
        public string $occurredAt,
        public string $idempotencyKey,
        public array $lines,
        public ?int $batchId = null,
        public ?int $headsTreated = null,
        public ?string $notes = null,
        public ?int $createdBy = null,
        public ?int $warehouseId = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?int $createdBy = null): self
    {
        $notes = isset($data['notes']) ? trim((string) $data['notes']) : '';

        return new self(
            (string) $data['occurred_at'],
            (string) $data['idempotency_key'],
            array_map(fn (array $line) => StockLineDTO::fromArray($line), array_values($data['lines'] ?? [])),
            isset($data['batch_id']) ? (int) $data['batch_id'] : null,
            isset($data['heads_treated']) ? (int) $data['heads_treated'] : null,
            $notes === '' ? null : $notes,
            $createdBy,
            isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
        );
    }
}
