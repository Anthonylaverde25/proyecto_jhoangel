<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\ActivityEntity;
use App\Core\Entities\BatchEntity;
use App\Core\Interfaces\IActivityRepository;
use App\Models\Activity;
use App\Models\CompanyActivity;

class EloquentActivityRepository implements IActivityRepository
{
    public function findAll(?int $companyId = null): array
    {
        $query = Activity::query();

        if ($companyId) {
            $query->with(['companies' => function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            }, 'batches' => function ($q) use ($companyId) {
                $q->where('company_id', $companyId)->with(['farm', 'batchType'])->withCount('caravans');
            }]);
        } else {
            $query->with(['batches' => function ($q) {
                $q->with(['farm', 'batchType'])->withCount('caravans');
            }]);
        }

        return $query->get()->map(function ($model) use ($companyId) {
            $isEnabled = (bool) $model->is_active;

            if ($companyId && $model->relationLoaded('companies')) {
                $pivotCompany = $model->companies->first();
                if ($pivotCompany && isset($pivotCompany->pivot->is_enabled)) {
                    $isEnabled = (bool) $pivotCompany->pivot->is_enabled;
                }
            }

            $entity = new ActivityEntity(
                $model->id,
                $model->name,
                $model->code,
                $isEnabled,
                $model->is_final
            );
            $entity->setBatches($model->batches->map(fn($b) => new BatchEntity(
                $b->id,
                $b->name,
                $b->farm_id ? (int) $b->farm_id : null,
                $b->observaciones,
                (bool) $b->is_active,
                $b->created_at,
                $b->farm?->name ?? 'Sin Granja',
                null,
                null,
                (int) $b->activity_id,
                $model->name,
                $model->code,
                (float) $b->current_weight,
                (int) $b->caravans_count,
                $b->batch_type_id ? (int) $b->batch_type_id : null,
                $b->batchType?->name,
                $b->batchType?->code
            ))->toArray());
            return $entity;
        })->toArray();
    }

    public function findEnabledByCompany(int $companyId): array
    {
        return Activity::whereHas('companies', function ($query) use ($companyId) {
            $query->where('company_id', $companyId)->where('is_enabled', true);
        })->with(['batches' => function ($query) use ($companyId) {
            $query->where('company_id', $companyId)->with(['farm', 'batchType'])->withCount('caravans');
        }])->get()->map(function ($model) {
            $entity = new ActivityEntity(
                $model->id,
                $model->name,
                $model->code,
                true,
                $model->is_final
            );
            $entity->setBatches($model->batches->map(fn($b) => new BatchEntity(
                $b->id,
                $b->name,
                $b->farm_id ? (int) $b->farm_id : null,
                $b->observaciones,
                (bool) $b->is_active,
                $b->created_at,
                $b->farm?->name ?? 'Sin Granja',
                null,
                null,
                (int) $b->activity_id,
                $model->name,
                $model->code,
                (float) $b->current_weight,
                (int) $b->caravans_count,
                $b->batch_type_id ? (int) $b->batch_type_id : null,
                $b->batchType?->name,
                $b->batchType?->code
            ))->toArray());
            return $entity;
        })->toArray();
    }

    public function toggleActivity(int $companyId, int $activityId, bool $isEnabled): bool
    {
        CompanyActivity::updateOrCreate(
            ['company_id' => $companyId, 'activity_id' => $activityId],
            ['is_enabled' => $isEnabled]
        );

        return true;
    }

    public function findByCode(string $code): ?ActivityEntity
    {
        $model = Activity::where('code', $code)->first();
        if (!$model) {
            return null;
        }

        return new ActivityEntity(
            $model->id,
            $model->name,
            $model->code,
            $model->is_active,
            $model->is_final
        );
    }
}
