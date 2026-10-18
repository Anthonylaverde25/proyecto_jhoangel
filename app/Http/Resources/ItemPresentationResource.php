<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\ItemPresentationEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ItemPresentationEntity $resource
 */
class ItemPresentationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getId(),
            'item_id' => $this->resource->getItemId(),
            'name' => $this->resource->getName(),
            'factor_to_item_unit' => $this->resource->getFactorToItemUnit(),
            'is_active' => $this->resource->isActive(),
        ];
    }
}
