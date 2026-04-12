<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Models\Caravan;
use App\Core\Entities\CaravanEntity;
use App\Core\ValueObjects\CaravanNumber;
use App\Core\Enums\AnimalCategory;

class CaravanMapper
{
    /**
     * Convierte un modelo Eloquent a una entidad de dominio.
     */
    public static function toEntity(Caravan $model): CaravanEntity
    {
        return new CaravanEntity(
            $model->id,
            new CaravanNumber((string) $model->identification),
            $model->category,
            (int) $model->teeth,
            $model->entry_weight ? (float) $model->entry_weight : null,
            $model->exit_weight ? (float) $model->exit_weight : null,
            $model->breed,
            $model->sex,
            $model->entry_date?->format('Y-m-d'),
        );
    }

    /**
     * Convierte una entidad de dominio a un modelo Eloquent.
     */
    public static function toModel(CaravanEntity $entity, ?Caravan $model = null): Caravan
    {
        if ($model === null) {
            $model = new Caravan();
        }

        $model->identification = $entity->getIdentification()->getValue();
        $model->category = $entity->getCategory();
        $model->teeth = $entity->getTeeth();
        $model->entry_weight = $entity->getEntryWeight();
        $model->exit_weight = $entity->getExitWeight();
        $model->breed = $entity->getBreed();
        $model->sex = $entity->getSex();
        $model->entry_date = $entity->getEntryDate();

        return $model;
    }
}
