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
            $model->category,
            $model->title,
            $model->description,
            $model->schema_definition,
            $model->status,
            $model->code,
            $model->created_at
        );
    }

    public static function toModel(WorkTemplateEntity $entity, ?WorkTemplate $model = null): WorkTemplate
    {
        if ($model === null) {
            $model = new WorkTemplate();
        }

        $model->company_id = $entity->getCompanyId();
        $model->category = $entity->getCategory();
        $model->title = $entity->getTitle();
        $model->description = $entity->getDescription();
        $model->schema_definition = $entity->getSchemaDefinition();
        $model->status = $entity->getStatus();
        $model->code = $entity->getCode();

        return $model;
    }
}
