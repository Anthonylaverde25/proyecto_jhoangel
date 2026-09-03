<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\AnimalSubcategoryEntity;
use App\Models\AnimalSubcategory;

final class AnimalSubcategoryMapper
{
    /**
     * Convert Eloquent model to Domain entity.
     */
    public static function toDomain(AnimalSubcategory $model): AnimalSubcategoryEntity
    {
        return new AnimalSubcategoryEntity(
            id: $model->id,
            categoryId: (int) $model->category_id,
            code: $model->code,
            name: $model->name,
            targetWeightMin: $model->target_weight_min !== null ? (float) $model->target_weight_min : null,
            targetWeightMax: $model->target_weight_max !== null ? (float) $model->target_weight_max : null,
            description: $model->description
        );
    }
}
