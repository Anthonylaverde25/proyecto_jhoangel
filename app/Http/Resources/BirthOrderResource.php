<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\BirthOrderAnimalEntity;
use App\Core\Entities\BirthOrderEntity;
use App\Core\Entities\TransferOrderHistoryEntity;
use App\Core\Enums\BirthOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A birth order with the batches its females come from and its progress by outcome, and — unless
 * `withRoll` is off, as in the list — its roll and history.
 *
 * Each line carries what the review and the screen need to suggest a sire without printing one:
 * the candidate sires of the gestation and the one the system would use if left empty.
 *
 * @property-read BirthOrderEntity $resource
 */
class BirthOrderResource extends JsonResource
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
        $headBySource = [];

        foreach ($order->getAnimals() as $animal) {
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
            'period_start' => $order->getPeriodStart(),
            'period_end' => $order->getPeriodEnd(),
            'source_batches' => $sourceBatches,
            'planned_head_count' => $order->getPlannedHeadCount(),
            'head_count' => count($order->getAnimals()),
            'resolved_head_count' => $order->resolvedCount(),
            'born_head_count' => $order->bornCount(),
            'lost_head_count' => $order->lostCount(),
            'stillborn_head_count' => $order->outcomeCount(BirthOutcome::STILLBORN),
            'abortion_head_count' => $order->outcomeCount(BirthOutcome::ABORTION),
            'pending_head_count' => $order->pendingCount(),
            'skipped_head_count' => $order->skippedCount(),
            'unplanned_head_count' => $order->unplannedCount(),
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
            'created_at' => $order->getCreatedAt()?->format(DATE_ATOM),
        ];

        if (!$this->withRoll) {
            return $data;
        }

        $data['animals'] = array_map(fn (BirthOrderAnimalEntity $a) => [
            'id' => $a->getId(),
            'caravan_id' => $a->getMotherCaravanId(),
            'identification' => $a->getMotherIdentification(),
            'category_label' => $a->getMotherCategoryLabel(),
            'gestation_id' => $a->getGestationId(),
            'gestation_start_date' => $a->getGestationStartDate(),
            'estimated_due_date' => $a->getEstimatedDueDate(),
            'gestation_stage' => $a->getGestationStage(),
            'sires' => $a->getSires(),
            'suggested_sire_id' => self::suggestedSireId($a->getSires()),
            'source_batch_id' => $a->getSourceBatchId(),
            'source_batch_name' => $a->getSourceBatchName(),
            'current_batch_id' => $a->getCurrentBatchId(),
            'current_batch_name' => $a->getCurrentBatchName(),
            'unplanned' => $a->isUnplanned(),
            'status' => $a->getStatus()->value,
            'outcome' => $a->getOutcome()?->value,
            'outcome_label' => $a->getOutcome()?->label(),
            'event_date' => $a->getEventDate(),
            'calf_caravan_id' => $a->getCalfCaravanId(),
            'calf_identification' => $a->getCalfIdentification(),
            'calf_sex' => $a->getCalfSex(),
            'calf_batch_id' => $a->getCalfBatchId(),
            'calf_batch_name' => $a->getCalfBatchName(),
            'executed_at' => $a->getExecutedAt()?->format(DATE_ATOM),
            'observations' => $a->getObservations(),
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

    /**
     * The sire the system uses when none is declared: the confirmed one, or the only candidate.
     * With several unconfirmed candidates there is none, and the calf waits in "Sires pendientes".
     *
     * @param list<array{id: int, identification: string, is_confirmed: bool}> $sires
     */
    public static function suggestedSireId(array $sires): ?int
    {
        foreach ($sires as $sire) {
            if ($sire['is_confirmed']) {
                return $sire['id'];
            }
        }

        return count($sires) === 1 ? $sires[0]['id'] : null;
    }
}
