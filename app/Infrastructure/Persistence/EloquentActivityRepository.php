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
    public function findAll(): array
    {
        return Activity::with(['batches' => function ($query) {
            $query->with('farm')->withCount('caravans');
        }])->get()->map(function ($model) {
            $entity = new ActivityEntity(
                $model->id,
                $model->name,
                $model->code,
                $model->is_active,
                $model->is_final
            );
            $entity->setBatches($model->batches->map(fn($b) => new BatchEntity(
                $b->id,
                $b->name,
                (int) $b->farm_id,
                $b->observaciones,
                (bool) $b->is_active,
                $b->created_at,
                $b->farm?->name ?? 'Sin Granja',
                null,
                null,
                (int) $b->activity_id,
                $model->name,
                (float) $b->current_weight,
                (int) $b->caravans_count
            ))->toArray());
            return $entity;
        })->toArray();
    }

    public function findEnabledByCompany(int $companyId): array
    {
        return Activity::whereHas('companies', function ($query) use ($companyId) {
            $query->where('company_id', $companyId)->where('is_enabled', true);
        })->with(['batches' => function ($query) use ($companyId) {
            $query->where('company_id', $companyId)->with('farm')->withCount('caravans');
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
                (int) $b->farm_id,
                $b->observaciones,
                (bool) $b->is_active,
                $b->created_at,
                $b->farm?->name ?? 'Sin Granja',
                null,
                null,
                (int) $b->activity_id,
                $model->name,
                (float) $b->current_weight,
                (int) $b->caravans_count
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
}
