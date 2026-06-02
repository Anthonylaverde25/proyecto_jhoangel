<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final readonly class WeanCaravanDTO
{
    public function __construct(
        public int $caravanId,
        public int $targetBatchId,
        public string $weaningDate,
        public float $weaningWeight,
        public ?string $newCategory = null,
        public ?string $notes = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['caravan_id'],
            (int) $data['target_batch_id'],
            (string) $data['weaning_date'],
            (float) $data['weaning_weight'],
            $data['new_category'] ?? null,
            $data['notes'] ?? null
        );
    }
}
