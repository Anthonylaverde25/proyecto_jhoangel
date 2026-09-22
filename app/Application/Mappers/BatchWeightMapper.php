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
            $model->weight !== null ? (float) $model->weight : null,
            $model->type,
            $model->weighing_date,
            $model->total_weight !== null ? (float) $model->total_weight : null,
            $model->caravans_count !== null ? (int) $model->caravans_count : null,
            $model->weighed_count !== null ? (int) $model->weighed_count : null,
            $model->weights_as_of
        );
    }
}
