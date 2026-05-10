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
        $model = $caravan->getId() !== null ? Caravan::find($caravan->getId()) : null;
        $model = CaravanMapper::toModel($caravan, $model);
        $model->save();

        return CaravanMapper::toEntity($model->load('breedRelation'));
    }

    public function findByIdentification(CaravanNumber $identification): ?CaravanEntity
    {
        $model = Caravan::with('breedRelation')
            ->where('identification', $identification->getValue())
            ->first();
        
        return $model ? CaravanMapper::toEntity($model) : null;
    }

    public function findByIdentificationGlobal(CaravanNumber $identification): ?CaravanEntity
    {
        $model = Caravan::withoutGlobalScopes()
            ->with('breedRelation')
            ->where('identification', $identification->getValue())
            ->first();
        
        return $model ? CaravanMapper::toEntity($model) : null;
    }

    public function findById(int $id): ?CaravanEntity
    {
        $model = Caravan::with('breedRelation')->find($id);
        
        return $model ? CaravanMapper::toEntity($model) : null;
    }

    public function findAll(): array
    {
        $models = Caravan::with(['breedRelation', 'batch'])->get();
        return $models->map(fn($model) => CaravanMapper::toEntity($model))->toArray();
    }

    public function delete(int $id): bool
    {
        return (bool) Caravan::destroy($id);
    }
}
