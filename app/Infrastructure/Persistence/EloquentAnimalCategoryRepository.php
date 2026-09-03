<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\AnimalCategoryMapper;
use App\Application\Mappers\AnimalSubcategoryMapper;
use App\Core\Entities\AnimalCategoryEntity;
use App\Core\Entities\AnimalSubcategoryEntity;
use App\Core\Interfaces\IAnimalCategoryRepository;
use App\Models\AnimalCategory;
use App\Models\AnimalSubcategory;

class EloquentAnimalCategoryRepository implements IAnimalCategoryRepository
{
    /**
     * @return AnimalCategoryEntity[]
     */
    public function all(): array
    {
        $categories = AnimalCategory::with('subcategories')->orderBy('id')->get();

        return $categories->map(fn (AnimalCategory $cat) => AnimalCategoryMapper::toDomain($cat))->all();
    }

    public function findById(int $id): ?AnimalCategoryEntity
    {
        $category = AnimalCategory::with('subcategories')->find($id);

        return $category ? AnimalCategoryMapper::toDomain($category) : null;
    }

    public function findByCode(string $code): ?AnimalCategoryEntity
    {
        $category = AnimalCategory::with('subcategories')->where('code', $code)->first();

        return $category ? AnimalCategoryMapper::toDomain($category) : null;
    }

    /**
     * @return AnimalSubcategoryEntity[]
     */
    public function getSubcategoriesByCategoryId(int $categoryId): array
    {
        $subcategories = AnimalSubcategory::where('category_id', $categoryId)->orderBy('id')->get();

        return $subcategories->map(fn (AnimalSubcategory $sub) => AnimalSubcategoryMapper::toDomain($sub))->all();
    }
}
