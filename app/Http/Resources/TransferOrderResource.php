<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\TransferOrderAnimalEntity;
use App\Core\Entities\TransferOrderDestinationEntity;
use App\Core\Entities\TransferOrderEntity;
use App\Core\Entities\TransferOrderHistoryEntity;
use App\Core\Enums\TransferOrderAnimalStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A transfer order with its destinations, and — unless `withRoll` is off, as in the list — its
 * roll and history.
 *
 * @property-read TransferOrderEntity $resource
 */
class TransferOrderResource extends JsonResource
{
    private bool $withRoll = true;

    public function summary(): self
    {
        $this->withRoll = false;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        $countByKey = [];
        $movedByKey = [];

        foreach ($order->getAnimals() as $animal) {
            $key = $animal->getDestinationKey() ?? '';
            $countByKey[$key] = ($countByKey[$key] ?? 0) + 1;

            if ($animal->getStatus() === TransferOrderAnimalStatus::MOVED) {
                $movedByKey[$key] = ($movedByKey[$key] ?? 0) + 1;
            }
        }

        $data = [
            'id' => $order->getId(),
            'code' => $order->getCode(),
            'status' => $order->getStatus()->value,
            'status_label' => $order->getStatus()->label(),
            'is_open' => $order->getStatus()->isOpen(),
            'is_editable' => $order->getStatus()->isEditable(),
            'kind' => $order->getKind()->value,
            'kind_label' => $order->getKind()->label(),
            'destination_mode' => $order->getDestinationMode(),
            'category_mode' => $order->getCategoryMode()->value,
            'category_mode_label' => $order->getCategoryMode()->label(),
            'source_batch' => ['id' => $order->getSourceBatchId(), 'name' => $order->getSourceBatchName()],
            'source_activity_name' => $order->getSourceActivityName(),
            'destination_activity' => ['id' => $order->getDestinationActivityId(), 'name' => $order->getDestinationActivityName()],
            'planned_head_count' => $order->getPlannedHeadCount(),
            'moved_head_count' => $order->movedCount(),
            'pending_head_count' => $order->pendingCount(),
            'skipped_head_count' => $order->skippedCount(),
            'unassigned_head_count' => $countByKey[''] ?? 0,
            'movement_date' => $order->getMovementDate(),
            'requested_by' => $order->getRequestedByUserId() !== null
                ? ['id' => $order->getRequestedByUserId(), 'name' => $order->getRequestedByUserName()]
                : null,
            'emitted_at' => $order->getEmittedAt()?->format(DATE_ATOM),
            'printed_at' => $order->getPrintedAt()?->format(DATE_ATOM),
            'first_executed_at' => $order->getFirstExecutedAt()?->format(DATE_ATOM),
            'closed_at' => $order->getClosedAt()?->format(DATE_ATOM),
            'responsable' => $order->getResponsable(),
            'observations' => $order->getObservations(),
            'closing_reason' => $order->getClosingReason(),
            'destinations' => array_map(fn (TransferOrderDestinationEntity $d) => [
                'id' => $d->getId(),
                'key' => $d->getKey(),
                'label' => $d->getLabel(),
                'target_batch_id' => $d->getTargetBatchId(),
                'target_batch_name' => $d->getTargetBatchName(),
                'new_batch_name' => $d->getNewBatchName(),
                'new_batch_type_id' => $d->getNewBatchTypeId(),
                'new_batch_type_name' => $d->getNewBatchTypeName(),
                'is_confined' => $d->isConfined(),
                'management_is_confined' => $d->effectiveIsConfined(),
                'resolved_batch_id' => $d->getResolvedBatchId(),
                'resolved_batch_name' => $d->getResolvedBatchName(),
                'planned_head_count' => $countByKey[$d->getKey()] ?? 0,
                'moved_head_count' => $movedByKey[$d->getKey()] ?? 0,
            ], $order->getDestinations()),
            'created_at' => $order->getCreatedAt()?->format(DATE_ATOM),
        ];

        if (!$this->withRoll) {
            return $data;
        }

        $data['animals'] = array_map(fn (TransferOrderAnimalEntity $a) => [
            'id' => $a->getId(),
            'caravan_id' => $a->getCaravanId(),
            'identification' => $a->getIdentification(),
            'sex' => $a->getSex(),
            'category_name' => $a->getCategoryName(),
            'category_label' => $a->getCurrentCategoryLabel(),
            'target_category_id' => $a->getTargetCategoryId(),
            'target_subcategory_id' => $a->getTargetSubcategoryId(),
            'target_category_label' => $a->getTargetCategoryLabel(),
            'current_batch_id' => $a->getCurrentBatchId(),
            'destination_key' => $a->getDestinationKey(),
            'status' => $a->getStatus()->value,
            'moved_at' => $a->getMovedAt()?->format(DATE_ATOM),
            'caravan_movement_id' => $a->getCaravanMovementId(),
        ], $order->getAnimals());

        $data['history'] = array_map(fn (TransferOrderHistoryEntity $h) => [
            'id' => $h->getId(),
            'from_status' => $h->getFromStatus(),
            'to_status' => $h->getToStatus(),
            'action_user' => $h->getActionUserId() !== null
                ? ['id' => $h->getActionUserId(), 'name' => $h->getActionUserName()]
                : null,
            'reason' => $h->getReason(),
            'metadata' => $h->getMetadata(),
            'created_at' => $h->getCreatedAt()?->format(DATE_ATOM),
        ], $order->getHistory());

        return $data;
    }
}
