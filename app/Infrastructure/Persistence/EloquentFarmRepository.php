<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\FarmEntity;
use App\Core\Interfaces\IFarmRepository;
use App\Models\Farm;
use App\Application\Mappers\FarmMapper;

class EloquentFarmRepository implements IFarmRepository
{
    public function findAll(): array
    {
        return Farm::all()
            ->map(fn (Farm $model) => FarmMapper::toEntity($model))
            ->toArray();
    }

    public function findById(int $id): ?FarmEntity
    {
        $model = Farm::find($id);
        return $model ? FarmMapper::toEntity($model) : null;
    }

    public function findByProviderId(int $providerId): array
    {
        return Farm::where('provider_id', $providerId)
            ->get()
            ->map(fn (Farm $model) => FarmMapper::toEntity($model))
            ->toArray();
    }

    public function save(FarmEntity $farm): FarmEntity
    {
        $model = $farm->getId() !== null ? Farm::find($farm->getId()) : null;
        $model = FarmMapper::toModel($farm, $model);
        $model->save();

        return FarmMapper::toEntity($model);
    }

    public function delete(int $id): bool
    {
        return (bool) Farm::destroy($id);
    }
}
