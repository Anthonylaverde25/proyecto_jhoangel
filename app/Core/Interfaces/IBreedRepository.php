<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\BreedEntity;

interface IBreedRepository
{
    /**
     * Find a breed by name, or create it if it doesn't exist.
     */
    public function findByNameOrCreate(string $name): BreedEntity;

    /**
     * Find a breed by ID.
     */
    public function findById(int $id): ?BreedEntity;

    /**
     * Get all breeds.
     *
     * @return array<BreedEntity>
     */
    public function getAll(): array;
}
