<?php

declare(strict_types=1);

namespace App\Application\DTOs\Semen;

/** One lot of an identification (SU3): an existing lot of the item, or a new code. */
final readonly class SemenLotShareDTO
{
    public function __construct(
        public ?int $semenLotId,
        public ?string $lotCode,
        public ?string $collectionDate,
        public string $quantity,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $code = isset($data['lot_code']) ? trim((string) $data['lot_code']) : '';

        return new self(
            isset($data['semen_lot_id']) ? (int) $data['semen_lot_id'] : null,
            $code === '' ? null : $code,
            isset($data['collection_date']) && $data['collection_date'] !== '' ? (string) $data['collection_date'] : null,
            (string) ($data['quantity'] ?? '0'),
        );
    }
}
