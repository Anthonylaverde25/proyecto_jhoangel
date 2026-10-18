<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\UnitOfMeasureEntity;
use App\Core\Enums\UnitDimension;
use App\Models\UnitOfMeasure;

class UnitOfMeasureMapper
{
    public static function toEntity(UnitOfMeasure $model): UnitOfMeasureEntity
    {
        return new UnitOfMeasureEntity(
            $model->id,
            (int) $model->company_id,
            $model->code,
            $model->name,
            $model->symbol,
            UnitDimension::from($model->dimension),
            $model->factor_to_base !== null ? (string) $model->factor_to_base : null,
            (bool) $model->is_base,
            (bool) $model->is_active,
            (int) $model->display_order,
            (int) ($model->items_count ?? 0),
        );
    }

    public static function toModel(UnitOfMeasureEntity $entity, ?UnitOfMeasure $model = null): UnitOfMeasure
    {
        $model ??= new UnitOfMeasure();

        $model->company_id = $entity->getCompanyId();
        $model->code = $entity->getCode();
        $model->name = $entity->getName();
        $model->symbol = $entity->getSymbol();
        $model->dimension = $entity->getDimension()->value;
        $model->factor_to_base = $entity->getFactorToBase();
        $model->is_base = $entity->isBase();
        $model->is_active = $entity->isActive();
        $model->display_order = $entity->getDisplayOrder();

        return $model;
    }
}
