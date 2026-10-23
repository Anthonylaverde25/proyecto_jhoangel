<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

use App\Core\Entities\ItemEntity;
use App\Core\Entities\WarehouseEntity;
use App\Core\Interfaces\IItemCategoryRepository;

/**
 * Semen is only kept in a semen tank (semen delivery S-0, SI6): it never enters, is counted in, or is
 * consumed from a general warehouse. A tank takes any item. An item is semen when its category is the
 * SEMEN system root or lies below it.
 */
final class SemenStorageRule
{
    public const SEMEN_CATEGORY_CODE = 'SEMEN';

    /** @var array<int, array<int, true>> company id => semen category ids */
    private array $semenCategories = [];

    public function __construct(
        private readonly IItemCategoryRepository $categories,
    ) {
    }

    public function isSemen(ItemEntity $item): bool
    {
        $categoryId = $item->getItemCategoryId();

        return $categoryId !== null && isset($this->semenCategoriesOf($item->getCompanyId())[$categoryId]);
    }

    /** Adds the error under $field when the semen item would be kept in a warehouse that is not a tank. */
    public function check(ItemEntity $item, WarehouseEntity $warehouse, string $field, FieldErrorBag $errors): void
    {
        if (!$warehouse->isCryoTank() && $this->isSemen($item)) {
            $errors->add($field, "{$item->getName()} es semen: sólo se guarda en un termo, y {$warehouse->label()} no es un termo.");
        }
    }

    /** @return array<int, true> */
    private function semenCategoriesOf(int $companyId): array
    {
        if (isset($this->semenCategories[$companyId])) {
            return $this->semenCategories[$companyId];
        }

        $children = [];
        $ids = [];
        foreach ($this->categories->findAll($companyId, true) as $category) {
            $children[$category->getParentId() ?? 0][] = (int) $category->getId();
            if ($category->getParentId() === null && $category->isSystem() && $category->getCode() === self::SEMEN_CATEGORY_CODE) {
                $ids[] = (int) $category->getId();
            }
        }
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($children[$ids[$i]] ?? [] as $child) {
                if (!in_array($child, $ids, true)) {
                    $ids[] = $child;
                }
            }
        }

        return $this->semenCategories[$companyId] = array_fill_keys($ids, true);
    }
}
