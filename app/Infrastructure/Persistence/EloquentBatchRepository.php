<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\BatchEntity;
use App\Core\Interfaces\IBatchRepository;
use App\Models\Batch;
use App\Application\Mappers\BatchMapper;

class EloquentBatchRepository implements IBatchRepository
{
    public function findAll(): array
    {
        return Batch::with('farm.provider')->get()
            ->map(fn (Batch $model) => BatchMapper::toEntity($model))
            ->toArray();
    }

    public function findById(int $id): ?BatchEntity
    {
        $model = Batch::with('farm.provider')->find($id);
        return $model ? BatchMapper::toEntity($model) : null;
    }

    public function findByNameAndFarmId(string $name, int $farmId): ?BatchEntity
    {
        $model = Batch::with('farm.provider')
            ->where('name', $name)
            ->where('farm_id', $farmId)
            ->first();
        return $model ? BatchMapper::toEntity($model) : null;
    }

    public function findByFarmId(int $farmId): array
    {
        return Batch::with('farm.provider')->where('farm_id', $farmId)
            ->get()
            ->map(fn (Batch $model) => BatchMapper::toEntity($model))
            ->toArray();
    }

    public function save(BatchEntity $batch): BatchEntity
    {
        $model = $batch->getId() !== null ? Batch::find($batch->getId()) : null;
        $model = BatchMapper::toModel($batch, $model);
        $model->save();

        return BatchMapper::toEntity($model);
    }

    public function delete(int $id): bool
    {
        return (bool) Batch::destroy($id);
    }
}
