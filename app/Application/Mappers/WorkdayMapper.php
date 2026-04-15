<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\WorkdayEntity;
use App\Core\Enums\WorkType;
use App\Models\Workday;

final class WorkdayMapper
{
    public static function toEntity(Workday $model): WorkdayEntity
    {
        return new WorkdayEntity(
            id: $model->id,
            code: $model->code,
            type: WorkType::from($model->type),
            workDate: $model->work_date,
            createdAt: $model->created_at,
            updatedAt: $model->updated_at,
        );
    }

    public static function toModel(WorkdayEntity $entity, ?Workday $model = null): Workday
    {
        $model = $model ?? new Workday();
        
        $model->code = $entity->getCode();
        $model->type = $entity->getType()->value;
        $model->work_date = $entity->getWorkDate();
        
        return $model;
    }
}
