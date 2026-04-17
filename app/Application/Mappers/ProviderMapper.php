<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Models\Provider;
use App\Core\Entities\ProviderEntity;
use App\Application\Mappers\FarmMapper;

class ProviderMapper
{
    public static function toEntity(Provider $model): ProviderEntity
    {
        $farms = [];
        if ($model->relationLoaded('farms')) {
            foreach ($model->farms as $farmModel) {
                $farms[] = FarmMapper::toEntity($farmModel);
            }
        }

        return new ProviderEntity(
            $model->id,
            $model->name,
            $model->commercial_name,
            $model->cuit,
            $model->location,
            $model->email,
            $model->phone,
            (bool) $model->is_active,
            $model->created_at,
            $farms
        );
    }

    public static function toModel(ProviderEntity $entity, ?Provider $model = null): Provider
    {
        if ($model === null) {
            $model = new Provider();
        }

        $model->name = $entity->getName();
        $model->commercial_name = $entity->getCommercialName();
        $model->cuit = $entity->getCuit();
        $model->location = $entity->getLocation();
        $model->email = $entity->getEmail();
        $model->phone = $entity->getPhone();
        $model->is_active = $entity->isActive();

        return $model;
    }
}
