<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Models\Batch;
use App\Core\Entities\BatchEntity;

class BatchMapper
{
    public static function toEntity(Batch $model): BatchEntity
    {
        return new BatchEntity(
            $model->id,
            $model->name,
            (int) $model->farm_id,
            $model->observaciones,
            (bool) $model->is_active,
            $model->created_at,
        );
    }

    public static function toModel(BatchEntity $entity, ?Batch $model = null): Batch
    {
        if ($model === null) {
            $model = new Batch();
        }

        $model->name = $entity->getName();
        $model->farm_id = $entity->getFarmId();
        $model->observaciones = $entity->getObservaciones();
        $model->is_active = $entity->isActive();

        return $model;
    }
}
