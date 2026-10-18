<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\ItemBehavior;
use App\Core\Enums\ItemType;
use App\Core\Exceptions\ItemCatalogException;

/**
 * An article or a service the company buys or uses (D9, D11). The unit is the stock unit of an
 * article or the billing unit of a service, chosen by the operator (D17).
 *
 * Unit, type and track_stock become immutable once the item has lines in provider documents or
 * stock operations (B2, plan 4.10).
 */
final class ItemEntity
{
    public const CODE_PATTERN = '/^[A-Z0-9_\-]{1,32}$/';

    /**
     * @param list<ItemPresentationEntity> $presentations
     * @param list<ItemProviderLinkEntity> $providerLinks
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private ?string $code,
        private string $name,
        private ?string $description,
        private ItemType $type,
        private bool $trackStock,
        private int $unitOfMeasureId,
        private ?int $itemCategoryId,
        private bool $isActive = true,
        private readonly ?UnitOfMeasureEntity $unit = null,
        private readonly ?string $categoryName = null,
        private readonly ?string $categoryPath = null,
        private readonly array $presentations = [],
        private readonly array $providerLinks = [],
        private readonly int $presentationsCount = 0,
        private readonly int $providersCount = 0,
    ) {
        // Only a new item may wait for the code the repository generates (Q1).
        if ($code === null && $id !== null) {
            throw ItemCatalogException::invalidCode('del ítem');
        }
        if ($code !== null) {
            self::assertCode($code);
        }
        self::assertName($name);
        self::assertBehavior($type, $trackStock);
    }

    private static function assertCode(string $code): void
    {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw ItemCatalogException::invalidCode('del ítem');
        }
    }

    private static function assertName(string $name): void
    {
        if (trim($name) === '') {
            throw ItemCatalogException::emptyName();
        }
    }

    /** I1: guarded here as well as by the MySQL CHECK, because tests run on SQLite. */
    private static function assertBehavior(ItemType $type, bool $trackStock): void
    {
        if ($type === ItemType::SERVICE && $trackStock) {
            throw ItemCatalogException::serviceWithStock();
        }
    }

    public function assignCode(string $code): void
    {
        self::assertCode($code);
        $this->code = $code;
    }

    public function updateDetails(string $name, ?string $description, ?string $code): void
    {
        self::assertName($name);
        if ($code !== null) {
            self::assertCode($code);
            $this->code = $code;
        }
        $this->name = $name;
        $this->description = $description;
    }

    public function changeBehavior(ItemBehavior $behavior, bool $hasLines = false): void
    {
        if ($hasLines && $behavior !== $this->getBehavior()) {
            throw ItemCatalogException::itemInUse($this->name);
        }
        $this->type = $behavior->type();
        $this->trackStock = $behavior->tracksStock();
    }

    public function changeUnit(int $unitOfMeasureId, bool $hasLines = false): void
    {
        if ($hasLines && $unitOfMeasureId !== $this->unitOfMeasureId) {
            throw ItemCatalogException::itemInUse($this->name);
        }
        $this->unitOfMeasureId = $unitOfMeasureId;
    }

    public function changeCategory(?int $itemCategoryId): void
    {
        $this->itemCategoryId = $itemCategoryId;
    }

    public function setActive(bool $active): void
    {
        $this->isActive = $active;
    }

    public function getId(): ?int { return $this->id; }
    public function getCompanyId(): int { return $this->companyId; }
    public function getCode(): ?string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getType(): ItemType { return $this->type; }
    public function tracksStock(): bool { return $this->trackStock; }
    public function getBehavior(): ItemBehavior { return ItemBehavior::of($this->type, $this->trackStock); }
    public function getUnitOfMeasureId(): int { return $this->unitOfMeasureId; }
    public function getItemCategoryId(): ?int { return $this->itemCategoryId; }
    public function isActive(): bool { return $this->isActive; }
    public function getUnit(): ?UnitOfMeasureEntity { return $this->unit; }
    public function getCategoryName(): ?string { return $this->categoryName; }
    public function getCategoryPath(): ?string { return $this->categoryPath; }
    /** @return list<ItemPresentationEntity> */
    public function getPresentations(): array { return $this->presentations; }
    /** @return list<ItemProviderLinkEntity> */
    public function getProviderLinks(): array { return $this->providerLinks; }
    public function getPresentationsCount(): int { return $this->presentationsCount; }
    public function getProvidersCount(): int { return $this->providersCount; }
}
