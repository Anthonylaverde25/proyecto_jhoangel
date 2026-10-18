<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\UnitDimension;
use App\Core\Exceptions\ItemCatalogException;

/**
 * A company-owned unit of measure (D14). A NULL factor marks a unit that never converts on its own
 * (dose, head, service, workday); only an item presentation bridges it to another unit.
 */
final class UnitOfMeasureEntity
{
    public const CODE_PATTERN = '/^[A-Z0-9_]{1,16}$/';

    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly string $code,
        private string $name,
        private string $symbol,
        private UnitDimension $dimension,
        private ?string $factorToBase,
        private readonly bool $isBase = false,
        private bool $isActive = true,
        private int $displayOrder = 1,
        private readonly int $itemsCount = 0,
    ) {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw ItemCatalogException::invalidCode('de la unidad');
        }
        self::assertFactor($factorToBase, $isBase);
    }

    private static function assertFactor(?string $factor, bool $isBase): void
    {
        if ($factor !== null && (float) $factor <= 0) {
            throw ItemCatalogException::nonPositiveFactor();
        }
        if ($isBase && ($factor === null || (float) $factor !== 1.0)) {
            throw ItemCatalogException::baseNeedsFactorOne();
        }
    }

    public function rename(string $name, string $symbol, int $displayOrder): void
    {
        $this->name = $name;
        $this->symbol = $symbol;
        $this->displayOrder = $displayOrder;
    }

    /**
     * U4: dimension and factor change the meaning of every item counted in this unit.
     */
    public function changeConversion(UnitDimension $dimension, ?string $factorToBase, int $itemsUsing): void
    {
        $changes = $dimension !== $this->dimension || !self::sameFactor($factorToBase, $this->factorToBase);
        if (!$changes) {
            return;
        }
        if ($itemsUsing > 0) {
            throw ItemCatalogException::unitInUse($this->name, $itemsUsing);
        }
        self::assertFactor($factorToBase, $this->isBase);
        $this->dimension = $dimension;
        $this->factorToBase = $factorToBase;
    }

    /**
     * U5 / DB2: a base never goes; U6: neither does the unit of an active item.
     */
    public function setActive(bool $active, int $activeItemsUsing): void
    {
        if (!$active && $this->isBase) {
            throw ItemCatalogException::baseCannotBeDeactivated($this->name, $this->dimension->label());
        }
        if (!$active && $activeItemsUsing > 0) {
            throw ItemCatalogException::unitOfActiveItems($this->name, $activeItemsUsing);
        }
        $this->isActive = $active;
    }

    private static function sameFactor(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return abs((float) $a - (float) $b) < 1e-10;
    }

    public function getId(): ?int { return $this->id; }
    public function getCompanyId(): int { return $this->companyId; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function getSymbol(): string { return $this->symbol; }
    public function getDimension(): UnitDimension { return $this->dimension; }
    public function getFactorToBase(): ?string { return $this->factorToBase; }
    public function isConvertible(): bool { return $this->factorToBase !== null; }
    public function isBase(): bool { return $this->isBase; }
    public function isActive(): bool { return $this->isActive; }
    public function getDisplayOrder(): int { return $this->displayOrder; }
    public function getItemsCount(): int { return $this->itemsCount; }
}
