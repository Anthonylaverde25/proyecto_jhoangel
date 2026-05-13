<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\CaravanWeightEntity;
use App\Core\Interfaces\ICaravanWeightRepository;
use App\Models\CaravanWeight;
use App\Application\Mappers\CaravanWeightMapper;

class EloquentCaravanWeightRepository implements ICaravanWeightRepository
{
    public function save(CaravanWeightEntity $weight): CaravanWeightEntity
    {
        $model = $weight->getId() !== null ? CaravanWeight::find($weight->getId()) : null;
        $model = CaravanWeightMapper::toModel($weight, $model);
        $model->save();

        return CaravanWeightMapper::toEntity($model);
    }

    public function findCurrentByCaravanId(int $caravanId): ?CaravanWeightEntity
    {
        $model = CaravanWeight::where('caravan_id', $caravanId)
            ->where('current', true)
            ->first();

        return $model ? CaravanWeightMapper::toEntity($model) : null;
    }

    public function findByCaravanId(int $caravanId): array
    {
        $models = CaravanWeight::where('caravan_id', $caravanId)
            ->orderBy('weighing_date', 'desc')
            ->get();

        return $models->map(fn($model) => CaravanWeightMapper::toEntity($model))->toArray();
    }

    public function markAllNonCurrentForCaravan(int $caravanId): void
    {
        CaravanWeight::where('caravan_id', $caravanId)
            ->update(['current' => false]);
    }
}
