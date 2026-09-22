<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BatchWeightResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'batch_id' => $this->getBatchId(),
            'activity_id' => $this->getActivityId(),
            'activity_name' => $this->getActivityName(),
            'weight' => $this->getWeight(),
            'total_weight' => $this->getTotalWeight(),
            'caravans_count' => $this->getCaravansCount(),
            'weighed_count' => $this->getWeighedCount(),
            'weights_as_of' => $this->getWeightsAsOf()?->format('Y-m-d'),
            'type' => $this->getType(),
            'weighing_date' => $this->getWeighingDate()->format('Y-m-d'),
        ];
    }
}
