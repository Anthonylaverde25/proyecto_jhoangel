<?php

declare(strict_types=1);

namespace App\Application\UseCases\Categories;

use App\Core\Entities\AnimalCategoryEntity;
use App\Core\Interfaces\IAnimalCategoryRepository;

final class ListAnimalCategoriesUseCase
{
    public function __construct(
        private readonly IAnimalCategoryRepository $categoryRepository
    ) {
    }

    /**
     * Get all categories with their nested subcategories.
     *
     * @return AnimalCategoryEntity[]
     */
    public function __invoke(): array
    {
        return $this->categoryRepository->all();
    }
}
