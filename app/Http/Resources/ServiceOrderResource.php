<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\ServiceOrderEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ServiceOrderEntity $resource
 */
class ServiceOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->resource->getId(),
            'company_id'           => $this->resource->getCompanyId(),
            'batch_id'             => $this->resource->getBatchId(),
            'origin_batch_id'      => $this->resource->getOriginBatchId(),
            'service_batch_id'     => $this->resource->getServiceBatchId(),
            'code'                 => $this->resource->getCode(),
            'status'               => $this->resource->getStatus()->value,
            'requested_by_user_id' => $this->resource->getRequestedByUserId(),
            'reviewed_by_user_id'  => $this->resource->getReviewedByUserId(),
            'approved_by_user_id'  => $this->resource->getApprovedByUserId(),
            'reviewed_at'          => $this->resource->getReviewedAt()?->format('Y-m-d H:i:s'),
            'approved_at'          => $this->resource->getApprovedAt()?->format('Y-m-d H:i:s'),
            'executed_at'          => $this->resource->getExecutedAt()?->format('Y-m-d H:i:s'),
            'planned_start_date'   => $this->resource->getPlannedStartDate(),
            'planned_end_date'     => $this->resource->getPlannedEndDate(),
            'actual_start_date'    => $this->resource->getActualStartDate(),
            'actual_end_date'      => $this->resource->getActualEndDate(),
            'target_bull_ratio'    => $this->resource->getTargetBullRatio(),
            'final_pregnancy_rate' => $this->resource->getFinalPregnancyRate(),
            'observations'         => $this->resource->getObservations(),
            'rejection_reason'     => $this->resource->getRejectionReason(),
            'male_caravan_ids'        => $this->resource->getMaleCaravanIds(),
            'active_male_caravan_ids' => $this->resource->getActiveMaleCaravanIds(),
            'all_male_caravan_ids'    => $this->resource->getAllMaleCaravanIds(),
            'female_caravan_ids'      => $this->resource->getFemaleCaravanIds(),
            'male_details'            => $this->resource->getMaleDetails(),
            'bull_replacements'       => $this->resource->getBullReplacements(),
            'service_type'              => $this->resource->getServiceType(),
            'is_controlled_service'     => $this->resource->isControlledService(),
            'female_sire_assignments'   => $this->resource->getFemaleSireAssignments(),
            'history'              => ServiceOrderHistoryResource::collection($this->resource->getHistory()),
            'created_at'           => $this->resource->getCreatedAt()?->format('Y-m-d H:i:s'),
            'updated_at'           => $this->resource->getUpdatedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
