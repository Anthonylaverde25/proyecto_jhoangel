<?php

declare(strict_types=1);

namespace App\Core\Entities;

/**
 * One breed of an entry order, with its coat colour when declared. Its position is printed as a
 * letter (1 → A) and is what a caravan of a multi-breed order refers to.
 */
final class EntryOrderBreedEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $position,
        private readonly int $breedId,
        private readonly ?int $colorId,
        private readonly ?string $breedName = null,
        private readonly ?string $colorName = null
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

    public function getLetter(): string
    {
        return chr(64 + $this->position);
    }

    public function getBreedId(): int
    {
        return $this->breedId;
    }

    public function getColorId(): ?int
    {
        return $this->colorId;
    }

    public function getBreedName(): ?string
    {
        return $this->breedName;
    }

    public function getColorName(): ?string
    {
        return $this->colorName;
    }

    /**
     * "Braford Colorado", or the breed alone when no colour was declared.
     */
    public function getLabel(): string
    {
        return trim(($this->breedName ?? '') . ' ' . ($this->colorName ?? ''));
    }
}
