<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Models\Batch;
use App\Core\Entities\BatchEntity;

class BatchMapper
{
    public static function toEntity(Batch $model): BatchEntity
    {
        $renspa = 'NO_DEFINIDO';
        if ($model->farm_id !== null) {
            if ($model->relationLoaded('farm') && $model->farm) {
                $renspa = $model->farm->renspa ?? 'NO_DEFINIDO';
            }
        } else {
            $company = ($model->relationLoaded('company') && $model->company)
                ? $model->company
                : \App\Models\Company::find($model->company_id);
            $renspa = $company ? ($company->renspa ?? 'NO_DEFINIDO') : 'NO_DEFINIDO';
        }

        $entity = new BatchEntity(
            $model->id,
            $model->name,
            $model->farm_id !== null ? (int) $model->farm_id : null,
            $model->observaciones,
            (bool) $model->is_active,
            $model->created_at,
            null, // farmName
            null, // providerId
            null, // providerName
            $model->activity_id,
            $model->activity?->name,
            $model->activity?->code,
            $model->current_weight,
            null, // caravansCount
            $model->batch_type_id ? (int) $model->batch_type_id : null,
            $model->batchType?->name,
            $model->batchType?->code,
            (bool) $model->is_system,
            $renspa,
            (bool) ($model->knows_to_eat ?? false),
            $model->age_in_months !== null ? (int) $model->age_in_months : null,
            $model->min_weight !== null ? (float) $model->min_weight : null,
            $model->max_weight !== null ? (float) $model->max_weight : null
        );

        if ($model->relationLoaded('farm') && $model->farm) {
            $entity->setFarmName($model->farm->name);
            
            if ($model->farm->relationLoaded('provider') && $model->farm->provider) {
                $entity->setProviderId($model->farm->provider_id);
                $entity->setProviderName($model->farm->provider->name);
            }
        }

        if ($model->relationLoaded('serviceDetail') && $model->serviceDetail) {
            $detail = $model->serviceDetail;
            $entity->setServiceDetail(new \App\Core\Entities\ServiceBatchDetailEntity(
                $detail->id,
                $detail->batch_id,
                $detail->female_category_id,
                $detail->male_category_id,
                $detail->female_subcategory_id,
                $detail->femaleCategory?->name,
                $detail->femaleCategory?->code,
                $detail->femaleSubcategory?->name,
                $detail->femaleSubcategory?->code,
                $detail->maleCategory?->name,
                $detail->maleCategory?->code,
                $detail->target_bull_ratio !== null ? (float) $detail->target_bull_ratio : null,
                $detail->planned_start_date ? (is_string($detail->planned_start_date) ? $detail->planned_start_date : $detail->planned_start_date->format('Y-m-d')) : null,
                $detail->planned_end_date ? (is_string($detail->planned_end_date) ? $detail->planned_end_date : $detail->planned_end_date->format('Y-m-d')) : null,
                $detail->notes
            ));
        }

        return $entity;
    }

    public static function toModel(BatchEntity $entity, ?Batch $model = null): Batch
    {
        if ($model === null) {
            $model = new Batch();
        }

        $model->name = $entity->getName();
        $model->farm_id = $entity->getFarmId();
        $model->activity_id = $entity->getActivityId();
        $model->current_weight = $entity->getCurrentWeight();
        $model->min_weight = $entity->getMinWeight();
        $model->max_weight = $entity->getMaxWeight();
        $model->knows_to_eat = $entity->knowsToEat();
        $model->age_in_months = $entity->getAgeInMonths();
        $model->observaciones = $entity->getObservaciones();
        $model->is_active = $entity->isActive();
        $model->is_system = $entity->isSystem();
        $model->batch_type_id = $entity->getBatchTypeId();

        return $model;
    }

}
