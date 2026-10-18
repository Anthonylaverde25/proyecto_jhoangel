<?php

declare(strict_types=1);

namespace App\Application\DTOs\ItemCatalog;

final readonly class ItemPresentationDTO
{
    public function __construct(
        public ?string $name,
        public ?string $factorToItemUnit,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['name']) ? trim((string) $data['name']) : null,
            isset($data['factor_to_item_unit']) ? (string) $data['factor_to_item_unit'] : null,
        );
    }
}
