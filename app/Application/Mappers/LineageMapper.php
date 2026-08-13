<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Models\CaravanLineage;
use App\Core\Entities\LineageEntity;

class LineageMapper
{
    /**
     * Convert an Eloquent CaravanLineage model to a LineageEntity.
     */
    public static function toEntity(CaravanLineage $model): LineageEntity
    {
        return new LineageEntity(
            $model->id,
            (int) $model->caravan_id,
            (int) $model->mother_id,
            $model->relationLoaded('mother') && $model->mother ? (string) $model->mother->identification : null,
            $model->father_id ? (int) $model->father_id : null,
            $model->relationLoaded('father') && $model->father ? (string) $model->father->identification : null,
            $model->gestation_id ? (int) $model->gestation_id : null,
            $model->birth_date instanceof \DateTimeInterface
                ? $model->birth_date->format('Y-m-d')
                : (string) $model->birth_date,
            (bool) $model->is_nursing,
            $model->sire_assigned_at instanceof \DateTimeInterface ? $model->sire_assigned_at : null,
            $model->sire_identification_method ? (string) $model->sire_identification_method : null,
            $model->sire_notes ? (string) $model->sire_notes : null
        );
    }

    /**
     * Convert a LineageEntity to an Eloquent CaravanLineage model.
     */
    public static function toModel(LineageEntity $entity, ?CaravanLineage $model = null): CaravanLineage
    {
        if ($model === null) {
            $model = new CaravanLineage();
        }

        $model->caravan_id                 = $entity->getCaravanId();
        $model->mother_id                  = $entity->getMotherId();
        $model->father_id                  = $entity->getFatherId();
        $model->gestation_id               = $entity->getGestationId();
        $model->birth_date                 = $entity->getBirthDate();
        $model->is_nursing                 = $entity->isNursing();
        $model->sire_assigned_at           = $entity->getSireAssignedAt();
        $model->sire_identification_method = $entity->getSireIdentificationMethod();
        $model->sire_notes                 = $entity->getSireNotes();

        return $model;
    }
}
