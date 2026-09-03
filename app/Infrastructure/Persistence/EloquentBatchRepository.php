<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\BatchEntity;
use App\Core\Interfaces\IBatchRepository;
use App\Models\Batch;
use App\Application\Mappers\BatchMapper;

class EloquentBatchRepository implements IBatchRepository
{
    private array $relations = [
        'farm.provider',
        'batchType',
        'activity',
        'serviceDetail.femaleCategory',
        'serviceDetail.femaleSubcategory',
        'serviceDetail.maleCategory',
    ];

    public function findAll(?string $batchType = null, ?string $scope = null): array
    {
        $query = Batch::with($this->relations);
        
        if ($scope === 'own') {
            $query->where(function ($q) {
                $q->whereNull('farm_id')
                  ->orWhereHas('farm', function ($fqb) {
                      $fqb->whereNull('provider_id');
                  });
            });
        } elseif ($scope === 'external') {
            $query->whereHas('farm', function ($fqb) {
                $fqb->whereNotNull('provider_id');
            });
        }

        if ($batchType !== null) {
            $query->whereHas('batchType', function ($q) use ($batchType) {
                $q->where('code', $batchType);
            });
        }

        return $query->get()
            ->map(fn (Batch $model) => BatchMapper::toEntity($model))
            ->toArray();
    }


    public function findById(int $id): ?BatchEntity
    {
        $model = Batch::with($this->relations)->find($id);
        return $model ? BatchMapper::toEntity($model) : null;
    }

    public function findByNameAndFarmId(string $name, int $farmId): ?BatchEntity
    {
        $model = Batch::with($this->relations)
            ->where('name', $name)
            ->where('farm_id', $farmId)
            ->first();
        return $model ? BatchMapper::toEntity($model) : null;
    }

    public function findByFarmId(int $farmId, ?string $batchType = null): array
    {
        $query = Batch::with($this->relations)->where('farm_id', $farmId);

        if ($batchType !== null) {
            $query->whereHas('batchType', function ($q) use ($batchType) {
                $q->where('code', $batchType);
            });
        }

        return $query->get()
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

    public function addWeight(int $batchId, float $weight, string $type, \DateTimeInterface $date, ?int $activityId = null): void
    {
        \App\Models\BatchWeight::create([
            'batch_id' => $batchId,
            'activity_id' => $activityId,
            'weight' => $weight,
            'type' => $type,
            'weighing_date' => $date->format('Y-m-d'),
        ]);

        // Sync current_weight to batch
        \App\Models\Batch::where('id', $batchId)->update(['current_weight' => $weight]);
    }

    public function getWeights(int $batchId): array
    {
        return \App\Models\BatchWeight::with('activity')
            ->where('batch_id', $batchId)
            ->orderBy('weighing_date', 'asc')
            ->get()
            ->map(fn (\App\Models\BatchWeight $model) => \App\Application\Mappers\BatchWeightMapper::toEntity($model))
            ->toArray();
    }

    public function findSystemBatchByType(string $typeCode): ?BatchEntity
    {
        $model = Batch::with(['farm.provider', 'batchType', 'activity'])
            ->where('is_system', true)
            ->whereHas('batchType', function ($q) use ($typeCode) {
                $q->where('code', $typeCode);
            })
            ->first();

        return $model ? BatchMapper::toEntity($model) : null;
    }
}

