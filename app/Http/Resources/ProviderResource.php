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
            'location'        => $this->resource->getLocation(),
            'email'           => $this->resource->getEmail(),
            'phone'           => $this->resource->getPhone(),
            'is_active'       => $this->resource->isActive(),
            'farms'           => FarmResource::collection($this->resource->getFarms()),
            'created_at'      => $this->resource->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
