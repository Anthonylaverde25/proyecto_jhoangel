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

        return CaravanMapper::toEntity($model->load(['breedRelation', 'currentWeight']));
    }

    public function findByIdentification(CaravanNumber $identification): ?CaravanEntity
    {
        $model = Caravan::with(['breedRelation', 'currentWeight'])
            ->where('identification', $identification->getValue())
            ->first();
        
        return $model ? CaravanMapper::toEntity($model) : null;
    }

    public function findByIdentificationGlobal(CaravanNumber $identification): ?CaravanEntity
    {
        $model = Caravan::withoutGlobalScopes()
            ->with(['breedRelation', 'currentWeight'])
            ->where('identification', $identification->getValue())
            ->first();
        
        return $model ? CaravanMapper::toEntity($model) : null;
    }

    public function findById(int $id): ?CaravanEntity
    {
        $model = Caravan::with(['breedRelation', 'currentWeight'])->find($id);
        
        return $model ? CaravanMapper::toEntity($model) : null;
    }

    public function findAll(): array
    {
        $models = Caravan::with(['breedRelation', 'batch', 'currentWeight'])->get();
        return $models->map(fn($model) => CaravanMapper::toEntity($model))->toArray();
    }

    public function delete(int $id): bool
    {
        return (bool) Caravan::destroy($id);
    }

    public function countByBatch(int $batchId): int
    {
        return Caravan::where('batch_id', $batchId)->count();
    }

    public function getAverageWeightByBatch(int $batchId): ?float
    {
        $avg = Caravan::where('batch_id', $batchId)
            ->join('caravan_weights', 'caravans.id', '=', 'caravan_weights.caravan_id')
            ->where('caravan_weights.current', true)
            ->avg('caravan_weights.weight');

        return $avg !== null ? (float) $avg : null;
    }
}
