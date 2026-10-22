<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * An active item as the line matcher sees it (Delivery G, G5): no code, because the provider's
 * codes are another system (A1). Presentations go by name only, as context.
 */
final readonly class FiscalCatalogItem
{
    /** @param list<string> $presentations */
    public function __construct(
        public int $id,
        public string $name,
        public string $type,
        public ?string $unit,
        public ?string $category,
        public ?string $description,
        public array $presentations,
    ) {
    }
}
