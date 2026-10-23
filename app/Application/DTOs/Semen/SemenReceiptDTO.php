<?php

declare(strict_types=1);

namespace App\Application\DTOs\Semen;

/** The semen entry as typed (S14): a tank, an optional provider and one line per sire, type and lot. */
final readonly class SemenReceiptDTO
{
    /** @param list<SemenReceiptLineDTO> $lines */
    public function __construct(
        public string $occurredAt,
        public ?int $warehouseId,
        public ?int $providerId,
        public ?string $notes,
        public string $idempotencyKey,
        public array $lines,
        public ?int $createdBy = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?int $createdBy = null): self
    {
        $notes = isset($data['notes']) ? trim((string) $data['notes']) : '';

        return new self(
            (string) $data['occurred_at'],
            isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
            isset($data['provider_id']) ? (int) $data['provider_id'] : null,
            $notes === '' ? null : $notes,
            (string) $data['idempotency_key'],
            array_map(fn (array $line) => SemenReceiptLineDTO::fromArray($line), array_values($data['lines'] ?? [])),
            $createdBy,
        );
    }
}
