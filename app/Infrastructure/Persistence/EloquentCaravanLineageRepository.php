<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\LineageEntity;
use App\Core\Interfaces\ICaravanLineageRepository;
use App\Models\CaravanLineage;
use App\Application\Mappers\LineageMapper;

class EloquentCaravanLineageRepository implements ICaravanLineageRepository
{
    public function save(LineageEntity $lineage): LineageEntity
    {
        $model = $lineage->getId() !== null ? CaravanLineage::find($lineage->getId()) : null;
        $model = LineageMapper::toModel($lineage, $model);
        $model->save();

        return LineageMapper::toEntity($model->load(['mother', 'father']));
    }

    public function findByCaravanId(int $caravanId): ?LineageEntity
    {
        $model = CaravanLineage::with(['mother', 'father'])
            ->where('caravan_id', $caravanId)
            ->first();

        return $model ? LineageMapper::toEntity($model) : null;
    }

    /**
     * @param int $motherId
     * @return LineageEntity[]
     */
    public function findOffspringByMotherId(int $motherId): array
    {
        $models = CaravanLineage::with(['mother', 'father'])
            ->where('mother_id', $motherId)
            ->get();

        return $models->map(fn($model) => LineageMapper::toEntity($model))->toArray();
    }

    public function wean(int $caravanId): void
    {
        CaravanLineage::where('caravan_id', $caravanId)->update(['is_nursing' => false]);
    }
}
