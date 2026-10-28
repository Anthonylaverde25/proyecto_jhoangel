<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\PartnerContactEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PartnerContactEntity $resource
 */
class PartnerContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->resource->getId(),
            'name'       => $this->resource->getName(),
            'position'   => $this->resource->getPosition(),
            'phone'      => $this->resource->getPhone(),
            'email'      => $this->resource->getEmail(),
            'notes'      => $this->resource->getNotes(),
            'is_default' => $this->resource->isDefault(),
        ];
    }
}
