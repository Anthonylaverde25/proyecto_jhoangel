<?php

declare(strict_types=1);

namespace App\Application\DTOs\Semen;

final readonly class SemenReceiptLineDTO
{
    public function __construct(
        public ?int $sireId,
        public ?string $semenType,
        public ?string $lotCode,
        public ?string $collectionDate,
        public string $quantity,
        public string $unitCost,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $lot = isset($data['lot_code']) ? trim((string) $data['lot_code']) : '';

        return new self(
            isset($data['semen_sire_id']) ? (int) $data['semen_sire_id'] : null,
            isset($data['semen_type']) ? (string) $data['semen_type'] : null,
            $lot === '' ? null : $lot,
            isset($data['collection_date']) && $data['collection_date'] !== '' ? (string) $data['collection_date'] : null,
            (string) ($data['quantity'] ?? '0'),
            (string) ($data['unit_cost'] ?? '0'),
        );
    }
}
