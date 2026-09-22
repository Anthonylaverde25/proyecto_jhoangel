<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final readonly class WeanCaravanDTO
{
    public function __construct(
        public int $caravanId,
        public int $targetBatchId,
        public string $weaningDate,
        public ?float $weaningWeight,
        public ?string $newCategory = null,
        public ?string $notes = null,
        public ?int $newCategoryId = null,
        public ?int $newSubcategoryId = null,
        public ?CreateBatchDTO $newBatch = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        $newBatch = isset($data['new_batch'])
            ? CreateBatchDTO::fromArray($data['new_batch'])
            : null;

        return new self(
            (int) ($data['caravan_id'] ?? 0),
            (int) ($data['target_batch_id'] ?? 0),
            (string) $data['weaning_date'],
            isset($data['weaning_weight']) && $data['weaning_weight'] !== '' ? (float) $data['weaning_weight'] : null,
            $data['new_category'] ?? null,
            $data['notes'] ?? null,
            isset($data['new_category_id']) ? (int) $data['new_category_id'] : null,
            isset($data['new_subcategory_id']) ? (int) $data['new_subcategory_id'] : null,
            $newBatch
        );
    }
}
