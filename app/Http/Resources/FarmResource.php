<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\FarmEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read FarmEntity $resource
 */
class FarmResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->resource->getId(),
            'name'        => $this->resource->getName(),
            'location'    => $this->resource->getLocation(),
            'provider_id' => $this->resource->getProviderId(),
            'is_active'   => $this->resource->isActive(),
            'created_at'  => $this->resource->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
