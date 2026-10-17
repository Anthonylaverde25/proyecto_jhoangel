<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\WeighingOrderAnimalEntity;
use App\Core\Entities\WeighingOrderEntity;
use App\Core\Enums\BatchWeighingEntry;
use App\Core\Enums\ShrunkCondition;
use App\Core\Enums\TransferOrderKind;
use App\Core\Enums\TransferOrderStatus;
use App\Core\Enums\WeighingOrderMode;
use App\Core\Enums\WeighingTypeCode;
use App\Core\ValueObjects\BatchWeighingMeasure;
use App\Core\ValueObjects\CageGroupWeighing;
use App\Models\WeighingOrder;
use App\Models\WeighingOrderAnimal;
use App\Models\WeighingOrderGroup;

final class WeighingOrderMapper
{
    public static function toEntity(WeighingOrder $model): WeighingOrderEntity
    {
        return new WeighingOrderEntity(
            id: (int) $model->id,
            companyId: (int) $model->company_id,
            code: (string) $model->code,
            status: TransferOrderStatus::from((string) $model->status),
            kind: TransferOrderKind::from((string) $model->kind),
            mode: WeighingOrderMode::from((string) $model->mode),
            type: WeighingTypeCode::from((string) $model->weighingType->code),
            batchId: $model->batch_id,
            shrunkCondition: ShrunkCondition::from((string) $model->shrunk_condition),
            shrinkPct: $model->shrink_pct !== null ? (float) $model->shrink_pct : null,
            weighingDate: $model->weighing_date->format('Y-m-d'),
            weighingTime: $model->weighing_time !== null ? substr((string) $model->weighing_time, 0, 5) : null,
            plannedHeadCount: (int) $model->planned_head_count,
            requestedByUserId: $model->requested_by_user_id,
            emittedAt: $model->emitted_at,
            firstExecutedAt: $model->first_executed_at,
            closedAt: $model->closed_at,
            responsable: $model->responsable,
            observations: $model->observations,
            measure: self::measure($model),
            animals: $model->animals->map(static fn (WeighingOrderAnimal $a) => new WeighingOrderAnimalEntity(
                id: (int) $a->id,
                caravanId: (int) $a->caravan_id,
                sourceBatchId: $a->source_batch_id,
                status: (string) $a->status,
                caravanWeightId: $a->caravan_weight_id,
                weighedAt: $a->weighed_at,
                identification: $a->caravan?->identification,
            ))->all(),
            batchName: $model->batch?->name,
            createdAt: $model->created_at,
        );
    }

    private static function measure(WeighingOrder $model): ?BatchWeighingMeasure
    {
        return match (BatchWeighingEntry::tryFrom((string) $model->batch_entry)) {
            BatchWeighingEntry::AVERAGE => BatchWeighingMeasure::fromAverage(
                (int) $model->declared_head_count,
                (float) $model->declared_average_weight
            ),
            BatchWeighingEntry::LOADS => BatchWeighingMeasure::fromLoads($model->groups->map(
                static fn (WeighingOrderGroup $g) => new CageGroupWeighing(
                    (int) $g->sequence,
                    (int) $g->head_count,
                    (float) $g->gross_weight,
                    (float) $g->tare_weight,
                )
            )->all()),
            null => null,
        };
    }
}
