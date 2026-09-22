<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Models\BatchType;
use App\Core\Entities\BatchTypeEntity;

class BatchTypeMapper
{
    public static function toEntity(BatchType $model, ?int $companyId = null): BatchTypeEntity
    {
        return new BatchTypeEntity(
            id: $model->id,
            companyId: $companyId ?? ($model->pivot?->company_id ? (int) $model->pivot->company_id : null),
            name: $model->pivot?->custom_name ?: $model->name,
            code: $model->code,
            description: $model->description,
            color: $model->pivot?->custom_color ?: $model->color,
            icon: $model->icon,
            isActive: isset($model->pivot?->is_enabled) ? (bool) $model->pivot->is_enabled : (bool) $model->is_active,
            activityId: $model->activity_id !== null ? (int) $model->activity_id : null,
            isSelectable: (bool) ($model->is_selectable ?? true)
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

        $model->name = $entity->getName();
        $model->code = $entity->getCode();
        $model->description = $entity->getDescription();
        $model->color = $entity->getColor();
        $model->icon = $entity->getIcon();
        $model->is_active = $entity->isActive();
        $model->activity_id = $entity->getActivityId();
        $model->is_selectable = $entity->isSelectable();

        return $model;
    }
}
