<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\ItemCategoryEntity;
use App\Models\ItemCategory;

class ItemCategoryMapper
{
    public static function toEntity(ItemCategory $model): ItemCategoryEntity
    {
        return new ItemCategoryEntity(
            $model->id,
            (int) $model->company_id,
            $model->parent_id !== null ? (int) $model->parent_id : null,
            $model->code,
            $model->name,
            (bool) $model->is_active,
            (int) $model->display_order,
            (int) ($model->items_count ?? 0),
        );
    }

    public static function toModel(ItemCategoryEntity $entity, ?ItemCategory $model = null): ItemCategory
    {
        $model ??= new ItemCategory();

        $model->company_id = $entity->getCompanyId();
        $model->parent_id = $entity->getParentId();
        $model->code = $entity->getCode();
        $model->name = $entity->getName();
        $model->is_active = $entity->isActive();
        $model->display_order = $entity->getDisplayOrder();

        return $model;
    }
}
