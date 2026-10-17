<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\CaravanWeightEntity;
use App\Core\Enums\ShrunkCondition;
use App\Core\Enums\WeighingMethod;
use App\Core\Enums\WeighingSource;
use App\Core\Enums\WeighingTypeCode;
use App\Models\CaravanWeight;

final class CaravanWeightMapper
{
    public static function toEntity(CaravanWeight $model): CaravanWeightEntity
    {
        $typeCode = $model->weighingType?->code;

        return new CaravanWeightEntity(
            id: $model->id,
            caravanId: (int) $model->caravan_id,
            weight: (float) $model->weight,
            current: (bool) $model->current,
            weighingDate: $model->weighing_date,
            notes: $model->notes,
            createdAt: $model->created_at,
            type: WeighingTypeCode::tryFrom((string) $typeCode) ?? WeighingTypeCode::CONTROL,
            method: WeighingMethod::tryFrom((string) $model->method) ?? WeighingMethod::INDIVIDUAL,
            shrunkCondition: ShrunkCondition::tryFrom((string) $model->shrunk_condition) ?? ShrunkCondition::FULL_WEIGHT,
            shrinkPct: $model->shrink_pct !== null ? (float) $model->shrink_pct : null,
            weighingTime: $model->weighing_time !== null ? substr((string) $model->weighing_time, 0, 5) : null,
            source: WeighingSource::tryFrom((string) $model->source) ?? WeighingSource::MANUAL,
            weighingOrderId: $model->weighing_order_id,
        );
    }

    /**
     * The type travels as a code in the domain; the repository resolves it to the catalog row.
     */
    public static function toModel(CaravanWeightEntity $entity, int $weighingTypeId, ?CaravanWeight $model = null): CaravanWeight
    {
        if ($model === null) {
            $model = new CaravanWeight();
        }

        $model->caravan_id = $entity->getCaravanId();
        $model->weighing_type_id = $weighingTypeId;
        $model->weight = $entity->getWeight();
        $model->current = $entity->isCurrent();
        $model->weighing_date = $entity->getWeighingDate();
        $model->weighing_time = $entity->getWeighingTime();
        $model->method = $entity->getMethod()->value;
        $model->shrunk_condition = $entity->getShrunkCondition()->value;
        $model->shrink_pct = $entity->getShrinkPct();
        $model->source = $entity->getSource()->value;
        $model->notes = $entity->getNotes();

        // The order tables arrive with the weighing orders; until then nothing sets it.
        if ($entity->getWeighingOrderId() !== null) {
            $model->weighing_order_id = $entity->getWeighingOrderId();
        }

        return $model;
    }
}
