<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\UnitOfMeasureEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read UnitOfMeasureEntity $resource
 */
class UnitOfMeasureResource extends JsonResource
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
            'symbol' => $this->resource->getSymbol(),
            'dimension' => $this->resource->getDimension()->value,
            'dimension_label' => $this->resource->getDimension()->label(),
            'factor_to_base' => $this->resource->getFactorToBase(),
            'is_base' => $this->resource->isBase(),
            'is_convertible' => $this->resource->isConvertible(),
            'is_active' => $this->resource->isActive(),
            'display_order' => $this->resource->getDisplayOrder(),
            // Lets the screen warn before an action U4 or U6 would refuse.
            'items_count' => $this->resource->getItemsCount(),
        ];
    }
}
