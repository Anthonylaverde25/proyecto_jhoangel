<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\CaravanMovementEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read CaravanMovementEntity $resource
 */
class CaravanMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->resource->getId(),
            'identification' => $this->resource->getCaravanIdentification(),
            'renspa'         => $this->resource->getRenspa(),
            'type'           => $this->resource->getType(),
            'movement_date'  => $this->resource->getMovementDate()->format('Y-m-d H:i:s'),
            'observations'   => $this->resource->getObservations(),
        ];
    }
}
