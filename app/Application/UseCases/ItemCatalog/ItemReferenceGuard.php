<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Core\Exceptions\ItemCatalogException;
use App\Core\Interfaces\IItemCategoryRepository;
use App\Core\Interfaces\IUnitOfMeasureRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * I2 and I3: the unit and the category an item points to are of its company and active. Shared by
 * the create and update use cases.
 */
final class ItemReferenceGuard
{
    public function __construct(
        private readonly IUnitOfMeasureRepository $units,
        private readonly IItemCategoryRepository $categories,
    ) {
    }

    public function assertUnit(int $unitId, int $companyId): void
    {
        $unit = $this->units->findById($unitId, $companyId)
            ?? throw new ModelNotFoundException("Unit of measure {$unitId} not found.");

        if (!$unit->isActive()) {
            throw ItemCatalogException::inactiveUnit($unit->getName());
        }
    }

    public function assertCategory(?int $categoryId, int $companyId): void
    {
        if ($categoryId === null) {
            return;
        }
        $category = $this->categories->findById($categoryId, $companyId)
            ?? throw new ModelNotFoundException("Item category {$categoryId} not found.");

        if (!$category->isActive()) {
            throw ItemCatalogException::inactiveCategory($category->getName());
        }
    }
}
