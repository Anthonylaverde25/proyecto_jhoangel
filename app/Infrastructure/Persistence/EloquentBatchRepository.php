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

    public function findActiveByName(string $name): ?BatchEntity
    {
        $model = Batch::with($this->relations)
            ->where('name', $name)
            ->where('is_active', true)
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

    public function addWeight(
        int $batchId,
        ?float $weight,
        string $type,
        \DateTimeInterface $date,
        ?int $activityId = null,
        ?float $totalWeight = null,
        ?int $caravansCount = null,
        ?int $weighedCount = null,
        ?\DateTimeInterface $weightsAsOf = null
    ): void {
        \App\Models\BatchWeight::create([
            'batch_id' => $batchId,
            'activity_id' => $activityId,
            'weight' => $weight,
            'total_weight' => $totalWeight,
            'caravans_count' => $caravansCount,
            'weighed_count' => $weighedCount,
            'weights_as_of' => $weightsAsOf?->format('Y-m-d'),
            'type' => $type,
            'weighing_date' => $date->format('Y-m-d'),
        ]);

        // Sync the derived snapshot on the batch. A null average is propagated as null:
        // an emptied batch has no average, and a zero there would be a fabricated fact.
        $snapshot = ['current_weight' => $weight];

        if ($totalWeight !== null) {
            $snapshot['total_weight'] = $totalWeight;
        }

        if ($caravansCount !== null) {
            $snapshot['caravans_count'] = $caravansCount;
        }

        if ($weighedCount !== null) {
            $snapshot['weighed_count'] = $weighedCount;
        }

        \App\Models\Batch::where('id', $batchId)->update($snapshot);
    }

    public function findLatestWeight(int $batchId): ?\App\Core\Entities\BatchWeightEntity
    {
        $model = \App\Models\BatchWeight::with('activity')
            ->where('batch_id', $batchId)
            ->orderBy('weighing_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return $model !== null ? \App\Application\Mappers\BatchWeightMapper::toEntity($model) : null;
    }

    public function getWeights(int $batchId): array
    {
        return \App\Models\BatchWeight::with('activity')
            ->where('batch_id', $batchId)
            ->orderBy('weighing_date', 'asc')
            // The closing snapshot and the movement share a date: insertion order is
            // what keeps the step from being drawn upside down.
            ->orderBy('id', 'asc')
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

