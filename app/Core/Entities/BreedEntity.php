<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class BreedEntity
{
    public function __construct(
        private readonly ?int $id,
        private string $name,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
