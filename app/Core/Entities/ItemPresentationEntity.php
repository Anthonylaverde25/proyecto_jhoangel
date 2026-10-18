<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Exceptions\ItemCatalogException;

/**
 * Commercial packaging of an item (D16): how many item units one presentation holds.
 */
final class ItemPresentationEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $itemId,
        private string $name,
        private string $factorToItemUnit,
        private bool $isActive = true,
    ) {
        self::assert($name, $factorToItemUnit);
    }

    private static function assert(string $name, string $factor): void
    {
        if (trim($name) === '') {
            throw ItemCatalogException::emptyName();
        }
        if ((float) $factor <= 0) {
            throw ItemCatalogException::nonPositiveFactor();
        }
    }

    public function update(string $name, string $factorToItemUnit): void
    {
        self::assert($name, $factorToItemUnit);
        $this->name = $name;
        $this->factorToItemUnit = $factorToItemUnit;
    }

    public function setActive(bool $active): void
    {
        $this->isActive = $active;
    }

    public function getId(): ?int { return $this->id; }
    public function getItemId(): int { return $this->itemId; }
    public function getName(): string { return $this->name; }
    public function getFactorToItemUnit(): string { return $this->factorToItemUnit; }
    public function isActive(): bool { return $this->isActive; }
}
