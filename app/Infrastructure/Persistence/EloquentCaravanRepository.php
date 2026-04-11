<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\CaravanEntity;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\ValueObjects\CaravanNumber;
use App\Models\Caravan;
use App\Application\Mappers\CaravanMapper;

class EloquentCaravanRepository implements ICaravanRepository
{
    public function save(CaravanEntity $caravan): CaravanEntity
    {
        $model = Caravan::find($caravan->getId());
        $model = CaravanMapper::toModel($caravan, $model);
        $model->save();

        return CaravanMapper::toEntity($model);
    }

    public function findByIdentification(CaravanNumber $identification): ?CaravanEntity
    {
        $model = Caravan::where('identification', $identification->getValue())->first();
        
        return $model ? CaravanMapper::toEntity($model) : null;
    }

    public function findById(int $id): ?CaravanEntity
    {
        $model = Caravan::find($id);
        
        return $model ? CaravanMapper::toEntity($model) : null;
    }

    public function findAll(): array
    {
        return Caravan::all()
            ->map(fn (Caravan $model) => CaravanMapper::toEntity($model))
            ->toArray();
    }

    public function delete(int $id): bool
    {
        return (bool) Caravan::destroy($id);
    }
}
