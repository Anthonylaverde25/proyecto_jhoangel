<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\CaravanMovementEntity;
use App\Core\Interfaces\ICaravanMovementRepository;
use App\Models\CaravanMovement;
use App\Application\Mappers\CaravanMovementMapper;

final class EloquentCaravanMovementRepository implements ICaravanMovementRepository
{
    public function save(CaravanMovementEntity $movement): CaravanMovementEntity
    {
        $model = CaravanMovementMapper::toModel($movement);
        $model->save();

        return CaravanMovementMapper::toEntity($model);
    }

    public function findByCaravanId(int $caravanId): array
    {
        $models = CaravanMovement::where('caravan_id', $caravanId)
            ->orderBy('movement_date', 'asc')
            ->get();

        return $models->map(fn($model) => CaravanMovementMapper::toEntity($model))->toArray();
    }
}
