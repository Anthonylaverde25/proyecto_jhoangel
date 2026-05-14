<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Models\TemplateType;
use App\Core\Entities\TemplateTypeEntity;

class TemplateTypeMapper
{
    public static function toEntity(TemplateType $model): TemplateTypeEntity
    {
        return new TemplateTypeEntity(
            $model->id,
            $model->company_id,
            $model->name,
            $model->code,
            $model->icon,
            $model->color,
            $model->description,
            (bool) $model->is_active,
            $model->created_at
        );
    }

    public static function toModel(TemplateTypeEntity $entity, ?TemplateType $model = null): TemplateType
    {
        if ($model === null) {
            $model = new TemplateType();
        }

        $model->company_id = $entity->getCompanyId();
        $model->name = $entity->getName();
        $model->code = $entity->getCode();
        $model->icon = $entity->getIcon();
        $model->color = $entity->getColor();
        $model->description = $entity->getDescription();
        $model->is_active = $entity->isActive();

        return $model;
    }
}
