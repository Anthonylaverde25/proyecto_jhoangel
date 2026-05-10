<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\ActivityEntity;
use App\Core\Interfaces\IActivityRepository;
use App\Models\Activity;
use App\Models\CompanyActivity;

class EloquentActivityRepository implements IActivityRepository
{
    public function findAll(): array
    {
        return Activity::all()->map(function ($model) {
            return new ActivityEntity(
                $model->id,
                $model->name,
                $model->code,
                $model->is_active
            );
        })->toArray();
    }

    public function findEnabledByCompany(int $companyId): array
    {
        return Activity::whereHas('companies', function ($query) use ($companyId) {
            $query->where('company_id', $companyId)->where('is_enabled', true);
        })->get()->map(function ($model) {
            return new ActivityEntity(
                $model->id,
                $model->name,
                $model->code,
                true
            );
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
