<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class BreedEntity
{
    public function __construct(
        private readonly ?int $id,
        private string $name,
        private readonly array $colors = [],
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

    /**
     * The coat colours the breed admits (breed_color), when they were loaded.
     *
     * @return list<array{id: int, name: string, code: ?string}>
     */
    public function getColors(): array
    {
        return $this->colors;
    }
}
