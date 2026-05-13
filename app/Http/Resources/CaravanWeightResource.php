<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Core\Entities\CaravanWeightEntity $resource
 */
class CaravanWeightResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->resource->getId(),
            'caravan_id'    => $this->resource->getCaravanId(),
            'weight'        => $this->resource->getWeight(),
            'current'       => $this->resource->isCurrent(),
            'weighing_date' => $this->resource->getWeighingDate()->format('Y-m-d'),
            'notes'         => $this->resource->getNotes(),
            'created_at'    => $this->resource->getCreatedAt()?->toDateTimeString(),
        ];
    }
}
