<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\WeighingTypeEntity;
use App\Core\Enums\WeighingTypeCode;
use App\Models\WeighingType;

final class WeighingTypeMapper
{
    public static function toEntity(WeighingType $model): WeighingTypeEntity
    {
        return new WeighingTypeEntity(
            id: (int) $model->id,
            code: WeighingTypeCode::from((string) $model->code),
            name: (string) $model->name,
            description: $model->description,
            displayOrder: (int) $model->display_order,
            isSystem: (bool) $model->is_system,
            isActive: (bool) $model->is_active,
        );
    }
}
