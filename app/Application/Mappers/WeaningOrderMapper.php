<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\TransferOrderHistoryEntity;
use App\Core\Entities\WeaningOrderAnimalEntity;
use App\Core\Entities\WeaningOrderDestinationEntity;
use App\Core\Entities\WeaningOrderEntity;
use App\Core\Enums\TransferOrderCategoryMode;
use App\Core\Enums\TransferOrderKind;
use App\Core\Enums\TransferOrderStatus;
use App\Core\Enums\WeaningOrderAnimalStatus;
use App\Core\Enums\WeaningType;
use App\Models\WeaningOrder;

class WeaningOrderMapper
{
    public static function toEntity(WeaningOrder $model): WeaningOrderEntity
    {
        $destinations = [];
        $keyById = [];

        if ($model->relationLoaded('destinations')) {
            foreach ($model->destinations as $destination) {
                $keyById[(int) $destination->id] = $destination->destination_key;
                $target = $destination->relationLoaded('targetBatch') ? $destination->targetBatch : null;

                $destinations[] = new WeaningOrderDestinationEntity(
                    id: (int) $destination->id,
                    key: $destination->destination_key,
                    label: $destination->label,
                    targetBatchId: $destination->target_batch_id,
                    newBatchName: $destination->new_batch_name,
                    isConfined: $destination->is_confined,
                    resolvedBatchId: $destination->resolved_batch_id,
                    targetBatchName: $target?->name,
                    resolvedBatchName: $destination->relationLoaded('resolvedBatch') ? $destination->resolvedBatch?->name : null,
                    targetBatchIsConfined: $target?->is_confined !== null ? (bool) $target->is_confined : null
                );
            }
        }

        $animals = [];

        if ($model->relationLoaded('animals')) {
            foreach ($model->animals as $line) {
                $caravan = $line->relationLoaded('caravan') ? $line->caravan : null;
                $lineage = $caravan !== null && $caravan->relationLoaded('lineage') ? $caravan->lineage : null;
                $mother = $lineage !== null && $lineage->relationLoaded('mother') ? $lineage->mother : null;
                $birthDate = $lineage?->birth_date;

                $animals[] = new WeaningOrderAnimalEntity(
                    id: (int) $line->id,
                    caravanId: (int) $line->caravan_id,
                    sourceBatchId: $line->source_batch_id,
                    destinationKey: $line->weaning_order_destination_id !== null
                        ? ($keyById[(int) $line->weaning_order_destination_id] ?? null)
                        : null,
                    status: WeaningOrderAnimalStatus::from($line->status),
                    weanedAt: $line->weaned_at,
                    caravanMovementId: $line->caravan_movement_id,
                    targetCategoryId: $line->target_category_id,
                    targetSubcategoryId: $line->target_subcategory_id,
                    identification: $caravan?->identification,
                    sex: $caravan?->sex instanceof \BackedEnum ? (string) $caravan->sex->value : $caravan?->sex,
                    motherIdentification: $mother?->identification,
                    sourceBatchName: $line->relationLoaded('sourceBatch') ? $line->sourceBatch?->name : null,
                    currentBatchId: $caravan?->batch_id !== null ? (int) $caravan->batch_id : null,
                    targetCategoryLabel: TransferOrderMapper::categoryLabel(
                        $line->relationLoaded('targetCategory') ? $line->targetCategory : null,
                        $line->relationLoaded('targetSubcategory') ? $line->targetSubcategory : null
                    ),
                    currentCategoryLabel: $caravan !== null ? TransferOrderMapper::categoryLabel(
                        $caravan->relationLoaded('categoryRelation') ? $caravan->categoryRelation : null,
                        $caravan->relationLoaded('subcategoryRelation') ? $caravan->subcategoryRelation : null
                    ) : null,
                    birthDate: $birthDate instanceof \DateTimeInterface
                        ? $birthDate->format('Y-m-d')
                        : ($birthDate !== null ? substr((string) $birthDate, 0, 10) : null),
                    currentCategoryId: $caravan?->category_id !== null ? (int) $caravan->category_id : null,
                    currentSubcategoryId: $caravan?->subcategory_id !== null ? (int) $caravan->subcategory_id : null
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

        return new WeaningOrderEntity(
            id: (int) $model->id,
            companyId: (int) $model->company_id,
            code: $model->code,
            status: TransferOrderStatus::from($model->status),
            kind: TransferOrderKind::tryFrom((string) $model->kind) ?? TransferOrderKind::PLANNED,
            destinationMode: $model->destination_mode,
            categoryMode: TransferOrderCategoryMode::tryFrom((string) $model->category_mode) ?? TransferOrderCategoryMode::KEEP,
            destinationActivityId: (int) $model->destination_activity_id,
            weaningType: WeaningType::tryFrom((string) $model->weaning_type),
            plannedHeadCount: (int) $model->planned_head_count,
            weaningDate: $model->weaning_date instanceof \DateTimeInterface
                ? $model->weaning_date->format('Y-m-d')
                : (string) $model->weaning_date,
            requestedByUserId: $model->requested_by_user_id,
            emittedAt: $model->emitted_at,
            printedAt: $model->printed_at,
            firstExecutedAt: $model->first_executed_at,
            closedAt: $model->closed_at,
            responsable: $model->responsable,
            observations: $model->observations,
            closingReason: $model->closing_reason,
            destinations: $destinations,
            animals: $animals,
            history: $history,
            createdAt: $model->created_at,
            destinationActivityName: $model->relationLoaded('destinationActivity') ? $model->destinationActivity?->name : null,
            requestedByUserName: $model->relationLoaded('requestedByUser') ? $model->requestedByUser?->name : null
        );
    }
}
