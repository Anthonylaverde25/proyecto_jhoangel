<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\ProviderEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\FarmResource;

/**
 * @property-read ProviderEntity $resource
 */
class ProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->resource->getId(),
            'name'            => $this->resource->getName(),
            'commercial_name' => $this->resource->getCommercialName(),
            'cuit'            => $this->resource->getCuit(),
            'cuit_locked'     => $this->resource->isCuitLocked(),
            'location'        => $this->resource->getLocation(),
            'email'           => $this->resource->getEmail(),
            'phone'           => $this->resource->getPhone(),
            'is_active'       => $this->resource->isActive(),
            'supplies_livestock' => $this->resource->suppliesLivestock(),
            'supplies_items'  => $this->resource->suppliesItems(),
            'farms'           => FarmResource::collection($this->resource->getFarms()),
            'created_at'      => $this->resource->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
