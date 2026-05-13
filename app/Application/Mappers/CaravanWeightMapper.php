<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\CaravanWeightEntity;
use App\Models\CaravanWeight;

final class CaravanWeightMapper
{
    public static function toEntity(CaravanWeight $model): CaravanWeightEntity
    {
        return new CaravanWeightEntity(
            $model->id,
            (int) $model->caravan_id,
            (float) $model->weight,
            (bool) $model->current,
            $model->weighing_date,
            $model->notes,
            $model->created_at
        );
    }

    public static function toModel(CaravanWeightEntity $entity, ?CaravanWeight $model = null): CaravanWeight
    {
        if ($model === null) {
            $model = new CaravanWeight();
        }

        $model->caravan_id = $entity->getCaravanId();
        $model->weight = $entity->getWeight();
        $model->current = $entity->isCurrent();
        $model->weighing_date = $entity->getWeighingDate();
        $model->notes = $entity->getNotes();

        return $model;
    }
}
