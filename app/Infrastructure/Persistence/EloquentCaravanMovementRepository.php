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
        $models = CaravanMovement::with('caravan')
            ->where('caravan_id', $caravanId)
            ->orderBy('movement_date', 'desc')
            ->get();

        return $models->map(fn($model) => CaravanMovementMapper::toEntity($model))->toArray();
    }

    public function findAll(int $limit = 100): array
    {
        $models = CaravanMovement::with('caravan')
            ->orderBy('movement_date', 'desc')
            ->limit($limit)
            ->get();

        return $models->map(fn($model) => CaravanMovementMapper::toEntity($model))->toArray();
    }
}
