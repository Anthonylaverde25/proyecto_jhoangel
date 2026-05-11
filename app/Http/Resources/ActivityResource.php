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
            'is_final' => $this->resource->isFinal(),
            'caravans_count' => $this->resource->getCaravansCount(),
            'batches' => array_map(fn($batch) => [
                'id' => $batch->getId(),
                'name' => $batch->getName(),
                'farm_name' => $batch->getFarmName(),
                'current_weight' => $batch->getCurrentWeight(),
                'count' => $batch->getCaravansCount(),
            ], $this->resource->getBatches()),
        ];
    }
}
