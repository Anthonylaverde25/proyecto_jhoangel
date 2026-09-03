<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\AnimalCategoryEntity;
use App\Core\Entities\AnimalSubcategoryEntity;

interface IAnimalCategoryRepository
{
    /**
     * Get all animal categories with their nested subcategories.
     *
     * @return AnimalCategoryEntity[]
     */
    public function all(): array;

    /**
     * Find a category by its ID.
     */
    public function findById(int $id): ?AnimalCategoryEntity;

    /**
     * Find a category by its unique code.
     */
    public function findByCode(string $code): ?AnimalCategoryEntity;

    /**
     * Get subcategories for a given category ID.
     *
     * @return AnimalSubcategoryEntity[]
     */
    public function getSubcategoriesByCategoryId(int $categoryId): array;
}
