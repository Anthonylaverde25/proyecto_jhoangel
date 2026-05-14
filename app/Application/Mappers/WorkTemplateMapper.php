<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Models\WorkTemplate;
use App\Core\Entities\WorkTemplateEntity;

class WorkTemplateMapper
{
    public static function toEntity(WorkTemplate $model): WorkTemplateEntity
    {
        return new WorkTemplateEntity(
            $model->id,
            $model->company_id,
            (int) $model->type_id,
            $model->title,
            $model->description,
            $model->schema_definition,
            $model->status,
            $model->created_at,
            $model->type?->name,
            $model->type?->color,
            $model->type?->icon
        );
    }

    public static function toModel(WorkTemplateEntity $entity, ?WorkTemplate $model = null): WorkTemplate
    {
        if ($model === null) {
            $model = new WorkTemplate();
        }

        $model->company_id = $entity->getCompanyId();
        $model->type_id = $entity->getTypeId();
        $model->title = $entity->getTitle();
        $model->description = $entity->getDescription();
        $model->schema_definition = $entity->getSchemaDefinition();
        $model->status = $entity->getStatus();

        return $model;
    }
}
