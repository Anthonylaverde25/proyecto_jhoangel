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
            'type' => $this->getType(),
            'weighing_date' => $this->getWeighingDate()->format('Y-m-d'),
        ];
    }
}
