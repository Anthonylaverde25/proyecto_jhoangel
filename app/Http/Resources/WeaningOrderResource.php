<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\TransferOrderHistoryEntity;
use App\Core\Entities\WeaningOrderAnimalEntity;
use App\Core\Entities\WeaningOrderDestinationEntity;
use App\Core\Entities\WeaningOrderEntity;
use App\Core\Enums\WeaningOrderAnimalStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A weaning order with its destinations and the breeding batches it takes calves from, and —
 * unless `withRoll` is off, as in the list — its roll and history.
 *
 * @property-read WeaningOrderEntity $resource
 */
class WeaningOrderResource extends JsonResource
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
        $weanedByKey = [];
        $headBySource = [];

        foreach ($order->getAnimals() as $animal) {
            $key = $animal->getDestinationKey() ?? '';
            $countByKey[$key] = ($countByKey[$key] ?? 0) + 1;

            if ($animal->getStatus() === WeaningOrderAnimalStatus::WEANED) {
                $weanedByKey[$key] = ($weanedByKey[$key] ?? 0) + 1;
            }

            $source = $animal->getSourceBatchId() ?? 0;
            $headBySource[$source] = ($headBySource[$source] ?? 0) + 1;
        }

        $sourceBatches = [];
        foreach ($order->sourceBatches() as $id => $name) {
            $sourceBatches[] = ['id' => $id, 'name' => $name, 'head_count' => $headBySource[$id] ?? 0];
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
            'weaning_type' => $order->getWeaningType()?->value,
            'weaning_type_label' => $order->getWeaningType()?->label(),
            'destination_activity' => ['id' => $order->getDestinationActivityId(), 'name' => $order->getDestinationActivityName()],
            'source_batches' => $sourceBatches,
            'planned_head_count' => $order->getPlannedHeadCount(),
            'weaned_head_count' => $order->weanedCount(),
            'pending_head_count' => $order->pendingCount(),
            'skipped_head_count' => $order->skippedCount(),
            'unassigned_head_count' => $countByKey[''] ?? 0,
            'weaning_date' => $order->getWeaningDate(),
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
            'destinations' => array_map(fn (WeaningOrderDestinationEntity $d) => [
                'id' => $d->getId(),
                'key' => $d->getKey(),
                'label' => $d->getLabel(),
                'target_batch_id' => $d->getTargetBatchId(),
                'target_batch_name' => $d->getTargetBatchName(),
                'new_batch_name' => $d->getNewBatchName(),
                'is_confined' => $d->isConfined(),
                'management_is_confined' => $d->effectiveIsConfined(),
                'resolved_batch_id' => $d->getResolvedBatchId(),
                'resolved_batch_name' => $d->getResolvedBatchName(),
                'planned_head_count' => $countByKey[$d->getKey()] ?? 0,
                'weaned_head_count' => $weanedByKey[$d->getKey()] ?? 0,
            ], $order->getDestinations()),
            'created_at' => $order->getCreatedAt()?->format(DATE_ATOM),
        ];

        if (!$this->withRoll) {
            return $data;
        }

        $data['animals'] = array_map(fn (WeaningOrderAnimalEntity $a) => [
            'id' => $a->getId(),
            'caravan_id' => $a->getCaravanId(),
            'identification' => $a->getIdentification(),
            'sex' => $a->getSex(),
            'mother_identification' => $a->getMotherIdentification(),
            'birth_date' => $a->getBirthDate(),
            'source_batch_id' => $a->getSourceBatchId(),
            'source_batch_name' => $a->getSourceBatchName(),
            'current_batch_id' => $a->getCurrentBatchId(),
            'category_label' => $a->getCurrentCategoryLabel(),
            'category_id' => $a->getCurrentCategoryId(),
            'subcategory_id' => $a->getCurrentSubcategoryId(),
            'target_category_id' => $a->getTargetCategoryId(),
            'target_subcategory_id' => $a->getTargetSubcategoryId(),
            'target_category_label' => $a->getTargetCategoryLabel(),
            'destination_key' => $a->getDestinationKey(),
            'status' => $a->getStatus()->value,
            'weaned_at' => $a->getWeanedAt()?->format(DATE_ATOM),
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
