<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final readonly class RecordCaravanWeightDTO
{
    public function __construct(
        public int $caravanId,
        public float $weight,
        public string $weighingDate,
        public ?string $notes = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['caravan_id'],
            (float) $data['weight'],
            (string) $data['weighing_date'],
            $data['notes'] ?? null
        );
    }
}
