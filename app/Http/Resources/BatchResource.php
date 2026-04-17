<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\BatchEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read BatchEntity $resource
 */
class BatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->resource->getId(),
            'name'          => $this->resource->getName(),
            'farm_id'       => $this->resource->getFarmId(),
            'farm_name'     => $this->resource->getFarmName(),
            'provider_id'   => $this->resource->getProviderId(),
            'provider_name' => $this->resource->getProviderName(),
            'observaciones' => $this->resource->getObservaciones(),
            'is_active'     => $this->resource->isActive(),
            'created_at'    => $this->resource->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
