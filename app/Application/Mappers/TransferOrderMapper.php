<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\TransferOrderAnimalEntity;
use App\Core\Entities\TransferOrderDestinationEntity;
use App\Core\Entities\TransferOrderEntity;
use App\Core\Entities\TransferOrderHistoryEntity;
use App\Core\Enums\TransferOrderAnimalStatus;
use App\Core\Enums\TransferOrderCategoryMode;
use App\Core\Enums\TransferOrderKind;
use App\Core\Enums\TransferOrderStatus;
use App\Core\Services\AnimalCategoryTextResolver;
use App\Models\AnimalCategory;
use App\Models\AnimalSubcategory;
use App\Models\TransferOrder;

class TransferOrderMapper
{
    public static function toEntity(TransferOrder $model): TransferOrderEntity
    {
        $destinations = [];
        $keyById = [];

        if ($model->relationLoaded('destinations')) {
            foreach ($model->destinations as $destination) {
                $keyById[(int) $destination->id] = $destination->destination_key;

                $destinations[] = new TransferOrderDestinationEntity(
                    id: (int) $destination->id,
                    key: $destination->destination_key,
                    label: $destination->label,
                    targetBatchId: $destination->target_batch_id,
                    newBatchName: $destination->new_batch_name,
                    newBatchTypeId: $destination->new_batch_type_id,
                    isConfined: $destination->is_confined,
                    resolvedBatchId: $destination->resolved_batch_id,
                    targetBatchName: $destination->relationLoaded('targetBatch') ? $destination->targetBatch?->name : null,
                    resolvedBatchName: $destination->relationLoaded('resolvedBatch') ? $destination->resolvedBatch?->name : null,
                    newBatchTypeName: $destination->relationLoaded('newBatchType') ? $destination->newBatchType?->name : null,
                    targetBatchIsConfined: $destination->relationLoaded('targetBatch') && $destination->targetBatch?->is_confined !== null
                        ? (bool) $destination->targetBatch->is_confined
                        : null
                );
            }
        }

        $animals = [];

        if ($model->relationLoaded('animals')) {
            foreach ($model->animals as $line) {
                $caravan = $line->relationLoaded('caravan') ? $line->caravan : null;

                $animals[] = new TransferOrderAnimalEntity(
                    id: (int) $line->id,
                    caravanId: (int) $line->caravan_id,
                    destinationKey: $line->transfer_order_destination_id !== null
                        ? ($keyById[(int) $line->transfer_order_destination_id] ?? null)
                        : null,
                    status: TransferOrderAnimalStatus::from($line->status),
                    movedAt: $line->moved_at,
                    caravanMovementId: $line->caravan_movement_id,
                    identification: $caravan?->identification,
                    sex: $caravan?->sex instanceof \BackedEnum ? (string) $caravan->sex->value : $caravan?->sex,
                    categoryName: $caravan !== null && $caravan->relationLoaded('categoryRelation') ? $caravan->categoryRelation?->name : null,
                    currentBatchId: $caravan?->batch_id !== null ? (int) $caravan->batch_id : null,
                    targetCategoryId: $line->target_category_id,
                    targetSubcategoryId: $line->target_subcategory_id,
                    targetCategoryLabel: self::categoryLabel(
                        $line->relationLoaded('targetCategory') ? $line->targetCategory : null,
                        $line->relationLoaded('targetSubcategory') ? $line->targetSubcategory : null
                    ),
                    currentCategoryLabel: $caravan !== null ? self::categoryLabel(
                        $caravan->relationLoaded('categoryRelation') ? $caravan->categoryRelation : null,
                        $caravan->relationLoaded('subcategoryRelation') ? $caravan->subcategoryRelation : null
                    ) : null
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

        $sourceBatch = $model->relationLoaded('sourceBatch') ? $model->sourceBatch : null;

        return new TransferOrderEntity(
            id: (int) $model->id,
            companyId: (int) $model->company_id,
            sourceBatchId: (int) $model->source_batch_id,
            destinationActivityId: (int) $model->destination_activity_id,
            code: $model->code,
            status: TransferOrderStatus::from($model->status),
            destinationMode: $model->destination_mode,
            plannedHeadCount: (int) $model->planned_head_count,
            movementDate: $model->movement_date instanceof \DateTimeInterface
                ? $model->movement_date->format('Y-m-d')
                : (string) $model->movement_date,
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
            sourceBatchName: $sourceBatch?->name,
            sourceActivityName: $sourceBatch !== null && $sourceBatch->relationLoaded('activity') ? $sourceBatch->activity?->name : null,
            destinationActivityName: $model->relationLoaded('destinationActivity') ? $model->destinationActivity?->name : null,
            requestedByUserName: $model->relationLoaded('requestedByUser') ? $model->requestedByUser?->name : null,
            kind: TransferOrderKind::tryFrom((string) $model->kind) ?? TransferOrderKind::PLANNED,
            categoryMode: TransferOrderCategoryMode::tryFrom((string) $model->category_mode) ?? TransferOrderCategoryMode::KEEP
        );
    }

    /**
     * The C/S label the sheet prints, written by the same rule the scan resolves with. Shared with
     * WeaningOrderMapper: both sheets print the category the same way.
     */
    public static function categoryLabel(?AnimalCategory $category, ?AnimalSubcategory $subcategory): ?string
    {
        if ($category === null) {
            return null;
        }

        return AnimalCategoryTextResolver::label(
            AnimalCategoryMapper::toDomain($category),
            $subcategory !== null && (int) $subcategory->category_id === (int) $category->id
                ? AnimalSubcategoryMapper::toDomain($subcategory)
                : null
        );
    }
}
