<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\PathogenCategory;

final class PathogenEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $code,
        private readonly string $name,
        private readonly PathogenCategory $category,
        private readonly bool $isDisqualifying,
        private readonly ?string $description = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCategory(): PathogenCategory
    {
        return $this->category;
    }

    public function isDisqualifying(): bool
    {
        return $this->isDisqualifying;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
