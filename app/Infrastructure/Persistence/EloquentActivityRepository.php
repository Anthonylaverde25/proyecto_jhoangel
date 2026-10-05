<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\ActivityEntity;
use App\Core\Entities\BatchEntity;
use App\Core\Interfaces\IActivityRepository;
use App\Models\Activity;
use App\Models\CompanyActivity;
use Illuminate\Support\Facades\DB;

class EloquentActivityRepository implements IActivityRepository
{
    public function findAll(?int $companyId = null): array
    {
        $query = Activity::query();

        if ($companyId) {
            $query->with(['companies' => function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            }, 'batches' => function ($q) use ($companyId) {
                $q->where('company_id', $companyId)->with(['farm', 'batchType'])->withCount(['caravans' => fn ($c) => $c->inPossession()])->withExists('outgoingMovements');
            }]);
        } else {
            $query->with(['batches' => function ($q) {
                $q->with(['farm', 'batchType'])->withCount(['caravans' => fn ($c) => $c->inPossession()])->withExists('outgoingMovements');
            }]);
        }

        $entities = $query->get()->map(function ($model) use ($companyId) {
            $isEnabled = (bool) $model->is_active;
            $isInitial = false;
            $isFinal = (bool) $model->is_final;
            $sortOrder = 99;

            if ($companyId && $model->relationLoaded('companies')) {
                $pivotCompany = $model->companies->first();
                if ($pivotCompany && isset($pivotCompany->pivot)) {
                    if (isset($pivotCompany->pivot->is_enabled)) {
                        $isEnabled = (bool) $pivotCompany->pivot->is_enabled;
                    }
                    if (isset($pivotCompany->pivot->is_initial)) {
                        $isInitial = (bool) $pivotCompany->pivot->is_initial;
                    }
                    if (isset($pivotCompany->pivot->is_final)) {
                        $isFinal = (bool) $pivotCompany->pivot->is_final;
                    }
                    if (isset($pivotCompany->pivot->sort_order)) {
                        $sortOrder = (int) $pivotCompany->pivot->sort_order;
                    }
                }
            }

            $entity = new ActivityEntity(
                $model->id,
                $model->name,
                $model->code,
                $isEnabled,
                $isFinal,
                $isInitial,
                $sortOrder
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
                $b->current_weight !== null ? (float) $b->current_weight : null,
                totalWeight: $b->total_weight !== null ? (float) $b->total_weight : null,
                weighedCount: $b->weighed_count !== null ? (int) $b->weighed_count : null,
                caravansCount: (int) $b->caravans_count,
                batchTypeId: $b->batch_type_id ? (int) $b->batch_type_id : null,
                batchTypeName: $b->batchType?->name,
                batchTypeCode: $b->batchType?->code,
                isConfined: $b->is_confined !== null ? (bool) $b->is_confined : null,
                wasEmptied: (bool) ($b->outgoing_movements_exists ?? false)
            ))->toArray());

            return $entity;
        })->toArray();

        // Ordenar entidades por sortOrder ascendente
        usort($entities, fn(ActivityEntity $a, ActivityEntity $b) => $a->getSortOrder() <=> $b->getSortOrder());

        return $entities;
    }

    public function findEnabledByCompany(int $companyId): array
    {
        $entities = Activity::whereHas('companies', function ($query) use ($companyId) {
            $query->where('company_id', $companyId)->where('is_enabled', true);
        })->with(['companies' => function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        }, 'batches' => function ($query) use ($companyId) {
            $query->where('company_id', $companyId)->with(['farm', 'batchType'])->withCount(['caravans' => fn ($c) => $c->inPossession()])->withExists('outgoingMovements');
        }])->get()->map(function ($model) {
            $pivot = $model->companies->first()?->pivot;
            $isInitial = $pivot ? (bool) $pivot->is_initial : false;
            $isFinal = $pivot ? (bool) $pivot->is_final : (bool) $model->is_final;
            $sortOrder = $pivot ? (int) $pivot->sort_order : 1;

            $entity = new ActivityEntity(
                $model->id,
                $model->name,
                $model->code,
                true,
                $isFinal,
                $isInitial,
                $sortOrder
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
                $b->current_weight !== null ? (float) $b->current_weight : null,
                totalWeight: $b->total_weight !== null ? (float) $b->total_weight : null,
                weighedCount: $b->weighed_count !== null ? (int) $b->weighed_count : null,
                caravansCount: (int) $b->caravans_count,
                batchTypeId: $b->batch_type_id ? (int) $b->batch_type_id : null,
                batchTypeName: $b->batchType?->name,
                batchTypeCode: $b->batchType?->code,
                isConfined: $b->is_confined !== null ? (bool) $b->is_confined : null,
                wasEmptied: (bool) ($b->outgoing_movements_exists ?? false)
            ))->toArray());

            return $entity;
        })->toArray();

        usort($entities, fn(ActivityEntity $a, ActivityEntity $b) => $a->getSortOrder() <=> $b->getSortOrder());

        return $entities;
    }

    public function toggleActivity(int $companyId, int $activityId, bool $isEnabled): bool
    {
        CompanyActivity::updateOrCreate(
            ['company_id' => $companyId, 'activity_id' => $activityId],
            ['is_enabled' => $isEnabled]
        );

        return true;
    }

    public function updateCompanyFlow(int $companyId, array $configs): void
    {
        DB::transaction(function () use ($companyId, $configs) {
            foreach ($configs as $config) {
                CompanyActivity::updateOrCreate(
                    [
                        'company_id' => $companyId,
                        'activity_id' => (int) $config['activity_id'],
                    ],
                    [
                        'is_enabled' => (bool) $config['is_enabled'],
                        'is_initial' => (bool) $config['is_initial'],
                        'is_final' => (bool) $config['is_final'],
                        'sort_order' => (int) $config['sort_order'],
                    ]
                );
            }
        });
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
            $model->is_final,
            false,
            1
        );
    }
}
