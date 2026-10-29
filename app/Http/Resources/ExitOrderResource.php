<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\ExitOrderAnimalEntity;
use App\Core\Entities\ExitOrderDteEntity;
use App\Core\Entities\ExitOrderEntity;
use App\Core\Entities\ExitOrderHistoryEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An exit order: the tray row (summary) or the drawer (roll with its snapshot, DTEs, totals and
 * history).
 *
 * @property-read ExitOrderEntity $resource
 */
class ExitOrderResource extends JsonResource
{
    private bool $summary = false;

    public function summary(): self
    {
        $this->summary = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        $data = [
            'id' => $order->getId(),
            'code' => $order->getCode(),
            'kind' => $order->getKind()->value,
            'kind_label' => $order->getKind()->label(),
            'reason' => $order->getReason()->value,
            'reason_label' => $order->getReason()->label(),
            'status' => $order->getStatus()->value,
            'status_label' => $order->getStatus()->label(),
            'buyer' => $order->getBuyerPartnerId() !== null ? [
                'id' => $order->getBuyerPartnerId(),
                'name' => $order->getBuyerName(),
                'cuit' => $order->getBuyerCuit(),
            ] : null,
            'exit_date' => $order->getExitDate(),
            'planned_head_count' => $order->getPlannedHeadCount(),
            'exited_count' => $order->exitedCount(),
            'pending_count' => $order->pendingCount(),
            'skipped_count' => $order->skippedCount(),
            'dte_head_count' => $order->dteHeadCount(),
            'dtes_count' => count($order->getDtes()),
            'without_dte' => $order->isWithoutDte(),
            'total_weight_kg' => $order->totalWeightKg(),
            'total_amount' => $order->totalAmount(),
            'emitted_at' => $order->getEmittedAt()?->format(DATE_ATOM),
            'closed_at' => $order->getClosedAt()?->format(DATE_ATOM),
            'closing_reason' => $order->getClosingReason(),
        ];

        if ($this->summary) {
            return $data;
        }

        return $data + [
            'responsable' => $order->getResponsable(),
            'observations' => $order->getObservations(),
            'first_executed_at' => $order->getFirstExecutedAt()?->format(DATE_ATOM),
            'animals' => array_map(fn (ExitOrderAnimalEntity $line) => $this->line($line), $order->getAnimals()),
            'dtes' => array_map(fn (ExitOrderDteEntity $dte) => [
                'id' => $dte->id,
                'dte_number' => $dte->dteNumber,
                'dte_date' => $dte->dteDate,
                'head_count' => $dte->headCount,
                'observations' => $dte->observations,
            ], $order->getDtes()),
            'history' => array_map(fn (ExitOrderHistoryEntity $row) => [
                'id' => $row->id,
                'from_status' => $row->fromStatus,
                'to_status' => $row->toStatus,
                'user' => $row->actionUserName,
                'reason' => $row->reason,
                'metadata' => $row->metadata,
                'created_at' => $row->createdAt?->format(DATE_ATOM),
            ], $order->getHistory()),
        ];
    }

    /**
     * A line of the roll. Batch, activity and category are those of the day it left; a line still
     * pending (or skipped) shows where the animal stands now.
     *
     * @return array<string, mixed>
     */
    private function line(ExitOrderAnimalEntity $line): array
    {
        $place = $line->getSnapshot() ?? $line->getCurrentPlace();

        return [
            'id' => $line->getId(),
            'caravan_id' => $line->getCaravanId(),
            'label' => $line->getLabel(),
            'status' => $line->getStatus()->value,
            'status_label' => $line->getStatus()->label(),
            'from_batch' => $place?->fromBatchId !== null ? ['id' => $place->fromBatchId, 'name' => $place->fromBatchName] : null,
            'activity' => $place?->activityId !== null ? ['id' => $place->activityId, 'name' => $place->activityName] : null,
            'category' => $place?->categoryId !== null ? ['id' => $place->categoryId, 'name' => $place->categoryName] : null,
            'was_pregnant' => $line->getSnapshot()?->wasPregnant,
            'weight_kg' => $line->getWeightKg(),
            'weight_estimated' => $line->isWeightEstimated(),
            'last_weight_kg' => $line->getLastWeightKg(),
            'price_per_kg' => $line->getPricePerKg(),
            'amount' => $line->getAmount(),
            'exited_at' => $line->getExitedAt(),
            'skipped_reason' => $line->getSkippedReason(),
        ];
    }
}
