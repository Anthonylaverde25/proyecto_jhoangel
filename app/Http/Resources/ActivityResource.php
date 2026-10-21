<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\ActivityEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ActivityEntity $resource
 */
class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getId(),
            'name' => $this->resource->getName(),
            'code' => $this->resource->getCode(),
            'is_enabled' => $this->resource->isEnabled(),
            'is_initial' => $this->resource->isInitial(),
            'is_final' => $this->resource->isFinal(),
            'sort_order' => $this->resource->getSortOrder(),
            'caravans_count' => $this->resource->getCaravansCount(),
            'pending_identification_count' => $this->resource->getPendingIdentificationCount(),
            'head_count' => $this->resource->getHeadCount(),
            'batches' => array_map(fn($batch) => [
                'id' => $batch->getId(),
                'name' => $batch->getName(),
                'farm_name' => $batch->getFarmName(),
                'current_weight' => $batch->getCurrentWeight(),
                'total_weight' => $batch->getTotalWeight(),
                'weighed_count' => $batch->getWeighedCount(),
                'count' => $batch->getCaravansCount(),
                'pending_identification_count' => $batch->getPendingIdentificationCount() ?? 0,
                'untagged_calf_count' => $batch->getUntaggedCalfCount() ?? 0,
                'head_count' => $batch->getHeadCount(),
                'activity_id' => $batch->getActivityId(),
                'batch_type_id' => $batch->getBatchTypeId(),
                'batch_type_name' => $batch->getBatchTypeName(),
                'batch_type_code' => $batch->getBatchTypeCode(),
                'is_confined' => $batch->isConfined(),
                'was_emptied' => $batch->wasEmptied(),
            ], $this->resource->getBatches()),
        ];
    }
}
