<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\ItemCategoryEntity;

interface IItemCategoryRepository
{
    /** @return list<ItemCategoryEntity> flat, ordered by display_order then name */
    public function findAll(int $companyId, bool $includeInactive): array;

    public function findById(int $id, int $companyId): ?ItemCategoryEntity;

    /** @return array<int, ?int> id => parent_id of every category of the company */
    public function parentMap(int $companyId): array;

    public function existsCode(string $code, int $companyId): bool;

    public function save(ItemCategoryEntity $category): ItemCategoryEntity;
}
