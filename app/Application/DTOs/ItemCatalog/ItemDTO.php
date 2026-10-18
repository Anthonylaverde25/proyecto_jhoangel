<?php

declare(strict_types=1);

namespace App\Application\DTOs\ItemCatalog;

use App\Core\Enums\ItemBehavior;

/**
 * Item data from a create or update request. On update, null means "not sent", except category
 * and description, which may be cleared and so track their presence apart.
 */
final readonly class ItemDTO
{
    public function __construct(
        public ?string $code,
        public ?string $name,
        public ?ItemBehavior $behavior,
        public ?int $unitOfMeasureId,
        public bool $categorySent,
        public ?int $itemCategoryId,
        public bool $descriptionSent,
        public ?string $description,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $description = isset($data['description']) ? trim((string) $data['description']) : null;

        return new self(
            isset($data['code']) && $data['code'] !== '' ? (string) $data['code'] : null,
            isset($data['name']) ? trim((string) $data['name']) : null,
            isset($data['behavior']) ? ItemBehavior::from((string) $data['behavior']) : null,
            isset($data['unit_of_measure_id']) ? (int) $data['unit_of_measure_id'] : null,
            array_key_exists('item_category_id', $data),
            isset($data['item_category_id']) ? (int) $data['item_category_id'] : null,
            array_key_exists('description', $data),
            $description === '' ? null : $description,
        );
    }
}
