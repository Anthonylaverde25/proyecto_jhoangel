<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Models\Farm;
use App\Core\Entities\FarmEntity;

class FarmMapper
{
    public static function toEntity(Farm $model): FarmEntity
    {
        return new FarmEntity(
            $model->id,
            $model->name,
            (string) $model->renspa,
            $model->location,
            $model->provider_id !== null ? (int) $model->provider_id : null,
            (bool) $model->is_active,
            $model->created_at,
            $model->company_id !== null ? (int) $model->company_id : null
        );
    }

    public static function toModel(FarmEntity $entity, ?Farm $model = null): Farm
    {
        if ($model === null) {
            $model = new Farm();
        }

        $model->name = $entity->getName();
        $model->renspa = $entity->getRenspa();
        $model->location = $entity->getLocation();
        $model->provider_id = $entity->getProviderId();
        $model->is_active = $entity->isActive();
        
        if ($entity->getCompanyId() !== null) {
            $model->company_id = $entity->getCompanyId();
        }

        return $model;
    }
}

