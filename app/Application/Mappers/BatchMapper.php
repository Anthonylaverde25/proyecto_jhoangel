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
            $model->total_weight !== null ? (float) $model->total_weight : null,
            $model->weighed_count !== null ? (int) $model->weighed_count : null,
            $model->caravans_count !== null ? (int) $model->caravans_count : null,
            $model->batch_type_id ? (int) $model->batch_type_id : null,
            $model->batchType?->name,
            $model->batchType?->code,
            (bool) $model->is_system,
            $renspa,
            (bool) ($model->knows_to_eat ?? false),
            $model->is_confined !== null ? (bool) $model->is_confined : null,
            $model->age_in_months !== null ? (int) $model->age_in_months : null,
            $model->min_weight !== null ? (float) $model->min_weight : null,
            $model->max_weight !== null ? (float) $model->max_weight : null
        );

        // Read with Batch::withPendingIdentification().
        if (array_key_exists('pending_identification_count', $model->getAttributes())) {
            $entity->setPendingIdentificationCount((int) $model->pending_identification_count);
        }

        if ($model->relationLoaded('farm') && $model->farm) {
            $entity->setFarmName($model->farm->name);
            
            if ($model->farm->relationLoaded('provider') && $model->farm->provider) {
                $entity->setProviderId($model->farm->provider_id);
                $entity->setProviderName($model->farm->provider->name);
            }
        }

        if ($model->relationLoaded('activeServiceOrder')) {
            $order = $model->activeServiceOrder;
            $entity->setActiveServiceOrder($order === null ? null : [
                'id' => (int) $order->id,
                'code' => (string) $order->code,
                'status' => (string) $order->status,
                'planned_start_date' => $order->planned_start_date?->format('Y-m-d'),
                'is_controlled_service' => (bool) $order->is_controlled_service,
            ]);
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
        $model->total_weight = $entity->getTotalWeight();
        $model->caravans_count = $entity->getCaravansCount();
        $model->weighed_count = $entity->getWeighedCount();
        $model->min_weight = $entity->getMinWeight();
        $model->max_weight = $entity->getMaxWeight();
        $model->knows_to_eat = $entity->knowsToEat();
        $model->is_confined = $entity->isConfined();
        $model->age_in_months = $entity->getAgeInMonths();
        $model->observaciones = $entity->getObservaciones();
        $model->is_active = $entity->isActive();
        $model->is_system = $entity->isSystem();
        $model->batch_type_id = $entity->getBatchTypeId();

        return $model;
    }

}
