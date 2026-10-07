<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\AnimalSex;

/**
 * One category of an entry order with the head bought of it ("30 Novillito"). Its position is
 * printed as a number (breeds take the letters) and is what a received caravan refers to when its
 * sex alone does not tell which category it is.
 */
final class EntryOrderCategoryEntity
{
    /**
     * @param ?string $categorySex animal_categories.sex: M, H or BOTH; null when not loaded
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $position,
        private readonly int $categoryId,
        private readonly ?int $headCount,
        private readonly ?string $categoryName = null,
        private readonly ?string $categorySex = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCategoryId(): int
    {
        return $this->categoryId;
    }

    public function getHeadCount(): ?int
    {
        return $this->headCount;
    }

    public function getCategoryName(): ?string
    {
        return $this->categoryName;
    }

    public function getCategorySex(): ?string
    {
        return $this->categorySex !== null ? strtoupper($this->categorySex) : null;
    }

    /**
     * Whether an animal of this sex can be of this category: a heifer category holds no males, a
     * calf category holds either.
     */
    public function admits(AnimalSex $sex): bool
    {
        return match ($this->getCategorySex()) {
            'BOTH' => true,
            'M' => $sex === AnimalSex::MALE,
            'H' => $sex === AnimalSex::FEMALE,
            default => false,
        };
    }

    /**
     * "30 Novillito", or the category alone when its head are not declared yet.
     */
    public function getLabel(): string
    {
        return trim(($this->headCount !== null ? "{$this->headCount} " : '') . ($this->categoryName ?? ''));
    }
}
