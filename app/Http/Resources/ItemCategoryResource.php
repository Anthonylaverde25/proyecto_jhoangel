<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\ItemCategoryEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ItemCategoryEntity $resource
 */
class ItemCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getId(),
            'code' => $this->resource->getCode(),
            'name' => $this->resource->getName(),
            'parent_id' => $this->resource->getParentId(),
            'is_active' => $this->resource->isActive(),
            'display_order' => $this->resource->getDisplayOrder(),
            'items_count' => $this->resource->getItemsCount(),
        ];
    }
}
