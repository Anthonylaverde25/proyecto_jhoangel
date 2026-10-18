<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Exceptions\ItemCatalogException;

/**
 * A node of the company's category tree (D10). Deactivating it leaves its children and items alone.
 */
final class ItemCategoryEntity
{
    public const CODE_PATTERN = '/^[A-Z0-9_]{1,32}$/';

    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private ?int $parentId,
        private readonly string $code,
        private string $name,
        private bool $isActive = true,
        private int $displayOrder = 1,
        private readonly int $itemsCount = 0,
    ) {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw ItemCatalogException::invalidCode('de la categoría');
        }
        if (trim($name) === '') {
            throw ItemCatalogException::emptyName();
        }
    }

    public function update(string $name, int $displayOrder): void
    {
        if (trim($name) === '') {
            throw ItemCatalogException::emptyName();
        }
        $this->name = $name;
        $this->displayOrder = $displayOrder;
    }

    /** The cycle check (C3) needs the whole tree, so the use case runs it before calling this. */
    public function moveTo(?int $parentId): void
    {
        $this->parentId = $parentId;
    }

    public function setActive(bool $active): void
    {
        $this->isActive = $active;
    }

    public function getId(): ?int { return $this->id; }
    public function getCompanyId(): int { return $this->companyId; }
    public function getParentId(): ?int { return $this->parentId; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function isActive(): bool { return $this->isActive; }
    public function getDisplayOrder(): int { return $this->displayOrder; }
    public function getItemsCount(): int { return $this->itemsCount; }
}
