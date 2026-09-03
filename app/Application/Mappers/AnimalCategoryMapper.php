<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\AnimalCategoryEntity;
use App\Models\AnimalCategory;

final class AnimalCategoryMapper
{
    /**
     * Convert Eloquent model to Domain entity.
     */
    public static function toDomain(AnimalCategory $model): AnimalCategoryEntity
    {
        $subcategories = [];

        if ($model->relationLoaded('subcategories')) {
            foreach ($model->subcategories as $subcategory) {
                $subcategories[] = AnimalSubcategoryMapper::toDomain($subcategory);
            }
        }

        return new AnimalCategoryEntity(
            id: $model->id,
            code: $model->code,
            name: $model->name,
            sex: $model->sex ?? 'BOTH',
            minAgeMonths: $model->min_age_months,
            maxAgeMonths: $model->max_age_months,
            minWeightKg: $model->min_weight_kg !== null ? (float) $model->min_weight_kg : null,
            maxWeightKg: $model->max_weight_kg !== null ? (float) $model->max_weight_kg : null,
            isReproductive: (bool) $model->is_reproductive,
            description: $model->description,
            subcategories: $subcategories
        );
    }
}
