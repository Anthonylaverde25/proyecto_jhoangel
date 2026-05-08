<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\CompanyEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read CompanyEntity $resource
 */
class CompanyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getId(),
            'name' => $this->resource->getName(),
            'renspa' => $this->resource->getRenspa(),
            'location' => $this->resource->getLocation(),
            'is_active' => $this->resource->isActive(),
            'role' => $this->resource->getRole(),
        ];
    }
}
