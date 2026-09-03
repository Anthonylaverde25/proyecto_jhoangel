<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\PathogenEntity;
use App\Core\Enums\PathogenCategory;
use App\Models\Pathogen;

final class PathogenMapper
{
    public static function toDomain(Pathogen $model): PathogenEntity
    {
        return new PathogenEntity(
            id: (int) $model->id,
            code: (string) $model->code,
            name: (string) $model->name,
            category: PathogenCategory::from($model->category),
            isDisqualifying: (bool) $model->is_disqualifying,
            description: $model->description
        );
    }
}
