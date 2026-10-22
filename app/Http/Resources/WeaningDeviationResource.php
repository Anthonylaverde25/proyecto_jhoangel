<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\WeaningOrderDeviationEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read WeaningOrderDeviationEntity $resource
 */
class WeaningDeviationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return [
            'id' => $d->getId(),
            'weaning_order_id' => $d->getWeaningOrderId(),
            'order_code' => $d->getOrderCode(),
            'order_status' => $d->getOrderStatus(),
            'weaning_order_animal_id' => $d->getWeaningOrderAnimalId(),
            'caravan_id' => $d->getCaravanId(),
            'identification' => $d->getIdentification(),
            'sex' => $d->getSex(),
            'source_batch_name' => $d->getSourceBatchName(),
            'kind' => $d->getKind()->value,
            'kind_label' => $d->getKind()->label(),
            'planned' => $d->getPlanned(),
            'actual' => $d->getActual(),
            'metadata' => $d->getMetadata(),
            'occurred_at' => $d->getOccurredAt()->format(DATE_ATOM),
            'recorded_by' => $d->getRecordedByUserId() !== null ? ['id' => $d->getRecordedByUserId(), 'name' => $d->getRecordedByUserName()] : null,
            'is_reviewed' => $d->isReviewed(),
            'reviewed_at' => $d->getReviewedAt()?->format(DATE_ATOM),
            'reviewed_by' => $d->getReviewedByUserId() !== null ? ['id' => $d->getReviewedByUserId(), 'name' => $d->getReviewedByUserName()] : null,
            'review_note' => $d->getReviewNote(),
        ];
    }
}
