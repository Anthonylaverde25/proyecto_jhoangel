<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Models\Caravan;
use App\Core\Entities\CaravanEntity;
use App\Core\ValueObjects\CaravanNumber;
use App\Core\Enums\AnimalCategory;
use App\Core\Enums\AnimalSex;
use App\Core\ValueObjects\FemaleReproductiveDetails;
use App\Core\Entities\GestationEntity;
use App\Core\Enums\GestationStage;

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
            $model->relationLoaded('breedRelation') && $model->breedRelation ? $model->breedRelation->name : $model->breed,
            $model->breed_id ? (int) $model->breed_id : null,
            $model->sex,
            $model->entry_date ? (is_string($model->entry_date) ? new \DateTime($model->entry_date) : $model->entry_date) : null,
            $model->created_at,
            $model->batch_id ? (int) $model->batch_id : null,
            $model->company_id ? (int) $model->company_id : null,
            $model->relationLoaded('batch') && $model->batch ? $model->batch->name : null,
            $model->relationLoaded('currentWeight') && $model->currentWeight ? (float) $model->currentWeight->weight : null,
            $model->relationLoaded('femaleDetail') && $model->femaleDetail ? new FemaleReproductiveDetails(
                $model->femaleDetail->is_empty,
                $model->femaleDetail->arrival_category
            ) : null,
            $model->relationLoaded('gestations') && $model->gestations ? $model->gestations->map(function ($g) {
                return new GestationEntity(
                    $g->id,
                    $g->start_date ? (is_string($g->start_date) ? $g->start_date : $g->start_date->format('Y-m-d')) : null,
                    $g->estimated_due_date ? (is_string($g->estimated_due_date) ? $g->estimated_due_date : $g->estimated_due_date->format('Y-m-d')) : null,
                    $g->is_current,
                    $g->result,
                    $g->end_date ? (is_string($g->end_date) ? $g->end_date : $g->end_date->format('Y-m-d')) : null,
                    $g->notes,
                    $g->gestation_stage ?? GestationStage::HEAD,
                    (float) ($g->gestation_months ?? 3.0)
                );
            })->toArray() : []
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
        $model->breed_id = $entity->getBreedId();
        $model->sex = $entity->getSex();
        $model->entry_date = $entity->getEntryDate();
        $model->batch_id = $entity->getBatchId();
        
        if ($entity->getCompanyId() !== null) {
            $model->company_id = $entity->getCompanyId();
        }

        return $model;
    }
}
