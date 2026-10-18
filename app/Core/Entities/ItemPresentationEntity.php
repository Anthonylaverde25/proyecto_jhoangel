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

    /** P4: once used in a line, name and content are what those papers said. */
    public function update(string $name, string $factorToItemUnit, bool $usedInLines = false): void
    {
        self::assert($name, $factorToItemUnit);
        if ($usedInLines && ($name !== $this->name || bccomp($factorToItemUnit, $this->factorToItemUnit, 4) !== 0)) {
            throw ItemCatalogException::presentationInUse($this->name);
        }
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
