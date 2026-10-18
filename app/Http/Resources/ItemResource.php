<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\ItemEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List shape of an item. ItemDetailResource adds presentations and providers.
 *
 * @property-read ItemEntity $resource
 */
class ItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $unit = $this->resource->getUnit();

        return [
            'id' => $this->resource->getId(),
            'code' => $this->resource->getCode(),
            'name' => $this->resource->getName(),
            'description' => $this->resource->getDescription(),
            'type' => $this->resource->getType()->value,
            'track_stock' => $this->resource->tracksStock(),
            'behavior' => $this->resource->getBehavior()->value,
            'is_active' => $this->resource->isActive(),
            'unit' => $unit === null ? null : [
                'id' => $unit->getId(),
                'code' => $unit->getCode(),
                'symbol' => $unit->getSymbol(),
                'name' => $unit->getName(),
                'is_convertible' => $unit->isConvertible(),
            ],
            'category' => $this->resource->getItemCategoryId() === null ? null : [
                'id' => $this->resource->getItemCategoryId(),
                'name' => $this->resource->getCategoryName(),
                'path' => $this->resource->getCategoryPath(),
            ],
            'presentations_count' => $this->resource->getPresentationsCount(),
            'providers_count' => $this->resource->getProvidersCount(),
        ];
    }
}
