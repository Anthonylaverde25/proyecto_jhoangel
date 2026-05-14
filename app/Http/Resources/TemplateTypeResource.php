<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\TemplateTypeEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read TemplateTypeEntity $resource
 */
class TemplateTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getId(),
            'name' => $this->resource->getName(),
            'code' => $this->resource->getCode(),
            'icon' => $this->resource->getIcon(),
            'color' => $this->resource->getColor(),
            'description' => $this->resource->getDescription(),
            'is_active' => $this->resource->isActive(),
        ];
    }
}
