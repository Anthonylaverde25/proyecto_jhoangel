<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\BirthOrderAnimalEntity;
use App\Core\Entities\BirthOrderEntity;
use App\Core\Entities\GestationEntity;
use App\Core\Entities\TransferOrderHistoryEntity;
use App\Core\Enums\BirthOrderAnimalStatus;
use App\Core\Enums\BirthOutcome;
use App\Core\Enums\GestationStage;
use App\Core\Enums\TransferOrderKind;
use App\Core\Enums\TransferOrderStatus;
use App\Models\BirthOrder;
use App\Models\CaravanGestation;

class BirthOrderMapper
{
    public static function toEntity(BirthOrder $model): BirthOrderEntity
    {
        $animals = [];

        if ($model->relationLoaded('animals')) {
            foreach ($model->animals as $line) {
                $mother = $line->relationLoaded('mother') ? $line->mother : null;
                $gestation = $line->relationLoaded('gestation') ? $line->gestation : null;
                $calf = $line->relationLoaded('calf') ? $line->calf : null;
                $currentBatch = $mother !== null && $mother->relationLoaded('batch') ? $mother->batch : null;

                $animals[] = new BirthOrderAnimalEntity(
                    id: (int) $line->id,
                    motherCaravanId: (int) $line->mother_caravan_id,
                    gestationId: $line->gestation_id,
                    sourceBatchId: $line->source_batch_id,
                    unplanned: (bool) $line->unplanned,
                    status: BirthOrderAnimalStatus::from($line->status),
                    outcome: BirthOutcome::tryFrom((string) $line->outcome),
                    eventDate: self::date($line->event_date),
                    calfCaravanId: $line->calf_caravan_id,
                    calfBatchId: $line->calf_batch_id,
                    executedAt: $line->executed_at,
                    observations: $line->observations,
                    motherIdentification: $mother?->identification,
                    motherCategoryLabel: $mother !== null ? TransferOrderMapper::categoryLabel(
                        $mother->relationLoaded('categoryRelation') ? $mother->categoryRelation : null,
                        $mother->relationLoaded('subcategoryRelation') ? $mother->subcategoryRelation : null
                    ) : null,
                    sourceBatchName: $line->relationLoaded('sourceBatch') ? $line->sourceBatch?->name : null,
                    currentBatchId: $mother?->batch_id !== null ? (int) $mother->batch_id : null,
                    currentBatchName: $currentBatch?->name,
                    gestationStartDate: self::date($gestation?->start_date),
                    estimatedDueDate: $gestation !== null ? self::dueDate($gestation) : null,
                    gestationStage: $gestation?->gestation_stage instanceof GestationStage
                        ? $gestation->gestation_stage->value
                        : ($gestation?->gestation_stage !== null ? (string) $gestation->gestation_stage : null),
                    sires: $gestation !== null && $gestation->relationLoaded('sires')
                        ? $gestation->sires->map(fn ($sire) => [
                            'id' => (int) $sire->id,
                            'identification' => (string) $sire->identification,
                            'is_confirmed' => (bool) $sire->pivot?->is_confirmed,
                        ])->values()->all()
                        : [],
                    calfIdentification: $calf?->identification,
                    calfSex: $calf?->sex instanceof \BackedEnum ? (string) $calf->sex->value : $calf?->sex,
                    calfBatchName: $line->relationLoaded('calfBatch') ? $line->calfBatch?->name : null
                );
            }
        }

        $history = [];

        if ($model->relationLoaded('history')) {
            foreach ($model->history as $entry) {
                $history[] = new TransferOrderHistoryEntity(
                    id: (int) $entry->id,
                    fromStatus: $entry->from_status,
                    toStatus: $entry->to_status,
                    actionUserId: $entry->action_user_id,
                    actionUserName: $entry->relationLoaded('actionUser') ? $entry->actionUser?->name : null,
                    reason: $entry->action_reason,
                    metadata: $entry->action_metadata,
                    createdAt: $entry->created_at
                );
            }
        }

        return new BirthOrderEntity(
            id: (int) $model->id,
            companyId: (int) $model->company_id,
            code: $model->code,
            status: TransferOrderStatus::from($model->status),
            kind: TransferOrderKind::tryFrom((string) $model->kind) ?? TransferOrderKind::PLANNED,
            periodStart: self::date($model->period_start),
            periodEnd: self::date($model->period_end),
            plannedHeadCount: (int) $model->planned_head_count,
            requestedByUserId: $model->requested_by_user_id,
            emittedAt: $model->emitted_at,
            printedAt: $model->printed_at,
            firstExecutedAt: $model->first_executed_at,
            closedAt: $model->closed_at,
            responsable: $model->responsable,
            observations: $model->observations,
            closingReason: $model->closing_reason,
            animals: $animals,
            history: $history,
            createdAt: $model->created_at,
            requestedByUserName: $model->relationLoaded('requestedByUser') ? $model->requestedByUser?->name : null
        );
    }

    /**
     * The due date the rest of the system shows: the stored one, or the one estimated from the
     * months of gestation at diagnosis. Delegated to the entity so both agree.
     */
    private static function dueDate(CaravanGestation $gestation): ?string
    {
        $stage = $gestation->gestation_stage instanceof GestationStage
            ? $gestation->gestation_stage
            : (GestationStage::tryFrom((string) $gestation->gestation_stage) ?? GestationStage::HEAD);

        return (new GestationEntity(
            id: (int) $gestation->id,
            startDate: self::date($gestation->start_date),
            estimatedDueDate: self::date($gestation->estimated_due_date),
            isCurrent: (bool) $gestation->is_current,
            success: $gestation->success,
            lossReasonId: $gestation->loss_reason_id,
            lossNotes: $gestation->loss_notes,
            endDate: self::date($gestation->end_date),
            notes: $gestation->notes,
            gestationStage: $stage,
            gestationMonths: (float) ($gestation->gestation_months ?? $stage->toDefaultMonths())
        ))->getEstimatedDueDate();
    }

    private static function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value !== null && $value !== '' ? substr((string) $value, 0, 10) : null;
    }
}
