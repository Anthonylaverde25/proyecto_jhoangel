<?php

declare(strict_types=1);

namespace App\Application\DTOs\Stock;

/**
 * A transfer between two warehouses of the company (delivery D, DD7).
 */
final readonly class StockTransferDTO
{
    /** @param list<StockLineDTO> $lines */
    public function __construct(
        public int $fromWarehouseId,
        public int $toWarehouseId,
        public string $occurredAt,
        public string $idempotencyKey,
        public array $lines,
        public ?string $notes = null,
        public ?int $createdBy = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?int $createdBy = null): self
    {
        $notes = isset($data['notes']) ? trim((string) $data['notes']) : '';

        return new self(
            (int) $data['from_warehouse_id'],
            (int) $data['to_warehouse_id'],
            (string) $data['occurred_at'],
            (string) $data['idempotency_key'],
            array_map(fn (array $line) => StockLineDTO::fromArray($line), array_values($data['lines'] ?? [])),
            $notes === '' ? null : $notes,
            $createdBy,
        );
    }
}
