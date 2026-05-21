<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Models\BatchType;
use App\Core\Entities\BatchTypeEntity;

class BatchTypeMapper
{
    public static function toEntity(BatchType $model): BatchTypeEntity
    {
        return new BatchTypeEntity(
            id: $model->id,
            companyId: (int) $model->company_id,
            name: $model->name,
            code: $model->code,
            description: $model->description,
            color: $model->color,
            icon: $model->icon,
            isActive: (bool) $model->is_active
        );
    }

    public static function toModel(BatchTypeEntity $entity, ?BatchType $model = null): BatchType
    {
        if ($model === null) {
            $model = new BatchType();
        }

        if ($entity->getId() !== null) {
            $model->id = $entity->getId();
        }
        $model->company_id = $entity->getCompanyId();
        $model->name = $entity->getName();
        $model->code = $entity->getCode();
        $model->description = $entity->getDescription();
        $model->color = $entity->getColor();
        $model->icon = $entity->getIcon();
        $model->is_active = $entity->isActive();

        return $model;
    }
}
