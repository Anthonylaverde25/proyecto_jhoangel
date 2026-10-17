<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\WeighingTypeMapper;
use App\Core\Entities\WeighingTypeEntity;
use App\Core\Interfaces\IWeighingTypeRepository;
use App\Models\WeighingType;

class EloquentWeighingTypeRepository implements IWeighingTypeRepository
{
    public function findAll(bool $onlyActive = false): array
    {
        return WeighingType::query()
            ->when($onlyActive, fn ($q) => $q->where('is_active', true))
            ->orderBy('display_order')
            ->get()
            ->map(fn (WeighingType $model) => WeighingTypeMapper::toEntity($model))
            ->all();
    }

    public function findById(int $id): ?WeighingTypeEntity
    {
        $model = WeighingType::find($id);

        return $model ? WeighingTypeMapper::toEntity($model) : null;
    }

    public function save(WeighingTypeEntity $type): void
    {
        // Only the active flag belongs to the tenant; the rest is written by the seeder.
        WeighingType::where('id', $type->getId())->update(['is_active' => $type->isActive()]);
    }
}
