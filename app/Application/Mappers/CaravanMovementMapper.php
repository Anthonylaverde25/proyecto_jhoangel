<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Models\CaravanMovement;
use App\Core\Entities\CaravanMovementEntity;

class CaravanMovementMapper
{
    public static function toEntity(CaravanMovement $model): CaravanMovementEntity
    {
        return new CaravanMovementEntity(
            $model->id,
            (int) $model->caravan_id,
            (int) $model->company_id,
            (string) $model->renspa,
            (string) $model->type,
            $model->movement_date,
            $model->observations,
            $model->caravan ? (string) $model->caravan->identification : null
        );
    }

    public static function toModel(CaravanMovementEntity $entity, ?CaravanMovement $model = null): CaravanMovement
    {
        if ($model === null) {
            $model = new CaravanMovement();
        }

        $model->caravan_id = $entity->getCaravanId();
        $model->company_id = $entity->getCompanyId();
        $model->renspa = $entity->getRenspa();
        $model->type = $entity->getType();
        $model->movement_date = $entity->getMovementDate();
        $model->observations = $entity->getObservations();

        return $model;
    }
}
