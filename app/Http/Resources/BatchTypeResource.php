<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\BatchTypeEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read BatchTypeEntity $resource
 */
class BatchTypeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->resource->getId(),
            'company_id'  => $this->resource->getCompanyId(),
            'name'        => $this->resource->getName(),
            'code'        => $this->resource->getCode(),
            'description' => $this->resource->getDescription(),
            'color'       => $this->resource->getColor(),
            'icon'        => $this->resource->getIcon(),
            'is_active'   => $this->resource->isActive(),
        ];
    }
}
