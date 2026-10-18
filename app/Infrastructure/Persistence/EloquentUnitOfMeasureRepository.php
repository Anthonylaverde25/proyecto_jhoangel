<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\UnitOfMeasureMapper;
use App\Core\Entities\UnitOfMeasureEntity;
use App\Core\Enums\UnitDimension;
use App\Core\Interfaces\IUnitOfMeasureRepository;
use App\Models\Item;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every query filters by company explicitly (D15, layer 2): it may run without a request context.
 */
class EloquentUnitOfMeasureRepository implements IUnitOfMeasureRepository
{
    public function findAll(int $companyId, bool $includeInactive): array
    {
        return $this->query($companyId)
            ->when(!$includeInactive, fn (Builder $q) => $q->where('is_active', true))
            ->withCount(['items' => fn (Builder $q) => $q->withoutGlobalScopes()])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->map(fn (UnitOfMeasure $model) => UnitOfMeasureMapper::toEntity($model))
            ->all();
    }

    public function findById(int $id, int $companyId): ?UnitOfMeasureEntity
    {
        $model = $this->query($companyId)
            ->withCount(['items' => fn (Builder $q) => $q->withoutGlobalScopes()])
            ->find($id);

        return $model ? UnitOfMeasureMapper::toEntity($model) : null;
    }

    public function findBase(UnitDimension $dimension, int $companyId): ?UnitOfMeasureEntity
    {
        $model = $this->query($companyId)
            ->where('dimension', $dimension->value)
            ->where('is_base', true)
            ->where('is_active', true)
            ->first();

        return $model ? UnitOfMeasureMapper::toEntity($model) : null;
    }

    public function countItemsUsing(int $unitId, int $companyId, bool $activeOnly): int
    {
        return Item::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('unit_of_measure_id', $unitId)
            ->when($activeOnly, fn (Builder $q) => $q->where('is_active', true))
            ->count();
    }

    public function save(UnitOfMeasureEntity $unit): UnitOfMeasureEntity
    {
        $model = $unit->getId() !== null ? $this->query($unit->getCompanyId())->findOrFail($unit->getId()) : null;
        $model = UnitOfMeasureMapper::toModel($unit, $model);
        $model->save();

        return $this->findById($model->id, $unit->getCompanyId());
    }

    private function query(int $companyId): Builder
    {
        return UnitOfMeasure::withoutGlobalScopes()->where('company_id', $companyId);
    }
}
