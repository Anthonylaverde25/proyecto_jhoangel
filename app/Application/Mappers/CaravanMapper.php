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
use App\Core\ValueObjects\SireEntry;

class CaravanMapper
{
    /**
     * Convierte un modelo Eloquent a una entidad de dominio.
     */
    public static function toEntity(Caravan $model): CaravanEntity
    {
        $breedName = $model->relationLoaded('breedRelation') && $model->breedRelation ? $model->breedRelation->name : null;
        $colorName = $model->relationLoaded('colorRelation') && $model->colorRelation ? $model->colorRelation->name : null;

        return new CaravanEntity(
            $model->id,
            new CaravanNumber((string) $model->identification),
            (int) $model->teeth,
            $model->entry_weight ? (float) $model->entry_weight : null,
            $model->exit_weight ? (float) $model->exit_weight : null,
            $model->breed_id ? (int) $model->breed_id : null,
            $breedName,
            $model->color_id ? (int) $model->color_id : null,
            $colorName,
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
                $sires = $g->relationLoaded('sires') && $g->sires ? $g->sires->map(function ($s) {
                    return new SireEntry(
                        (int) $s->id,
                        (string) $s->identification,
                        (bool) ($s->pivot->is_confirmed ?? false)
                    );
                })->toArray() : [];

                return new GestationEntity(
                    $g->id,
                    $g->start_date ? (is_string($g->start_date) ? $g->start_date : $g->start_date->format('Y-m-d')) : null,
                    $g->estimated_due_date ? (is_string($g->estimated_due_date) ? $g->estimated_due_date : $g->estimated_due_date->format('Y-m-d')) : null,
                    $g->is_current,
                    $g->success !== null ? (bool) $g->success : null,
                    $g->loss_reason_id ? (int) $g->loss_reason_id : null,
                    $g->loss_notes,
                    $g->end_date ? (is_string($g->end_date) ? $g->end_date : $g->end_date->format('Y-m-d')) : null,
                    $g->notes,
                    $g->gestation_stage ?? GestationStage::HEAD,
                    (float) ($g->gestation_months ?? 3.0),
                    $sires,
                    $g->service_order_id ? (int) $g->service_order_id : null
                );
            })->toArray() : [],
            $model->relationLoaded('lineage') && $model->lineage ? LineageMapper::toEntity($model->lineage) : null,
            $model->renspa ?? 'NO_DEFINIDO',
            $model->provider_id ? (int) $model->provider_id : null,
            $model->relationLoaded('provider') && $model->provider ? $model->provider->name : null,
            \App\Core\ValueObjects\CaravanProvenance::fromArray($model->provenance_metadata),
            $model->category_id ? (int) $model->category_id : null,
            $model->relationLoaded('categoryRelation') && $model->categoryRelation ? $model->categoryRelation->code : null,
            $model->relationLoaded('categoryRelation') && $model->categoryRelation ? $model->categoryRelation->name : null,
            $model->subcategory_id ? (int) $model->subcategory_id : null,
            $model->relationLoaded('subcategoryRelation') && $model->subcategoryRelation ? $model->subcategoryRelation->code : null,
            $model->relationLoaded('subcategoryRelation') && $model->subcategoryRelation ? $model->subcategoryRelation->name : null,
            false,
            $model->relationLoaded('batch') && $model->batch && $model->batch->relationLoaded('farm') && $model->batch->farm ? $model->batch->farm->name : null
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
        $model->category_id = $entity->getCategoryId();
        $model->subcategory_id = $entity->getSubcategoryId();
        $model->teeth = $entity->getTeeth();
        $model->entry_weight = $entity->getEntryWeight();
        $model->exit_weight = $entity->getExitWeight();
        $model->breed_id = $entity->getBreedId();
        $model->color_id = $entity->getColorId();
        $model->sex = $entity->getSex();
        $model->entry_date = $entity->getEntryDate();
        $model->batch_id = $entity->getBatchId();
        $model->renspa = $entity->getRenspa();
        $model->provider_id = $entity->getProviderId();
        $model->provenance_metadata = $entity->getProvenance()?->toArray();
        
        if ($entity->getCompanyId() !== null) {
            $model->company_id = $entity->getCompanyId();
        }

        return $model;
    }
}
