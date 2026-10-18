<?php

declare(strict_types=1);

namespace App\Application\DTOs\ItemCatalog;

/**
 * Category data from a create or update request. On update, null means "not sent", except the
 * parent, whose presence is tracked apart because null is a valid parent (the root).
 */
final readonly class ItemCategoryDTO
{
    public function __construct(
        public ?string $code,
        public ?string $name,
        public bool $parentSent,
        public ?int $parentId,
        public ?int $displayOrder,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['code']) && $data['code'] !== '' ? (string) $data['code'] : null,
            isset($data['name']) ? trim((string) $data['name']) : null,
            array_key_exists('parent_id', $data),
            isset($data['parent_id']) ? (int) $data['parent_id'] : null,
            isset($data['display_order']) ? (int) $data['display_order'] : null,
        );
    }
}
