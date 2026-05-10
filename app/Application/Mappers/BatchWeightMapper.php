<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\BatchWeightEntity;
use App\Models\BatchWeight;

final class BatchWeightMapper
{
    public static function toEntity(BatchWeight $model): BatchWeightEntity
    {
        return new BatchWeightEntity(
            $model->id,
            $model->batch_id,
            $model->activity_id,
            $model->activity?->name,
            (float) $model->weight,
            $model->type,
            $model->weighing_date
        );
    }
}
