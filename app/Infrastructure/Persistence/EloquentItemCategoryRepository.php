<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\ItemCategoryMapper;
use App\Core\Entities\ItemCategoryEntity;
use App\Core\Interfaces\IItemCategoryRepository;
use App\Models\ItemCategory;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every query filters by company explicitly (D15, layer 2): it may run without a request context.
 */
class EloquentItemCategoryRepository implements IItemCategoryRepository
{
    public function findAll(int $companyId, bool $includeInactive): array
    {
        return $this->query($companyId)
            ->when(!$includeInactive, fn (Builder $q) => $q->where('is_active', true))
            ->withCount(['items' => fn (Builder $q) => $q->withoutGlobalScopes()])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ItemCategory $model) => ItemCategoryMapper::toEntity($model))
            ->all();
    }

    public function findById(int $id, int $companyId): ?ItemCategoryEntity
    {
        $model = $this->query($companyId)
            ->withCount(['items' => fn (Builder $q) => $q->withoutGlobalScopes()])
            ->find($id);

        return $model ? ItemCategoryMapper::toEntity($model) : null;
    }

    public function parentMap(int $companyId): array
    {
        return $this->query($companyId)
            ->pluck('parent_id', 'id')
            ->map(fn ($parent) => $parent !== null ? (int) $parent : null)
            ->all();
    }

    public function existsCode(string $code, int $companyId): bool
    {
        return $this->query($companyId)->where('code', $code)->exists();
    }

    public function save(ItemCategoryEntity $category): ItemCategoryEntity
    {
        $model = $category->getId() !== null ? $this->query($category->getCompanyId())->findOrFail($category->getId()) : null;
        $model = ItemCategoryMapper::toModel($category, $model);
        $model->save();

        return $this->findById($model->id, $category->getCompanyId());
    }

    private function query(int $companyId): Builder
    {
        return ItemCategory::withoutGlobalScopes()->where('company_id', $companyId);
    }
}
