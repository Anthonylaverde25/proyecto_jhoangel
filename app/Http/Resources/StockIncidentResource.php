<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\StockIncidentEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read StockIncidentEntity $resource
 */
class StockIncidentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return self::shape($this->resource);
    }

    /** @return array<string, mixed> */
    public static function shape(StockIncidentEntity $incident): array
    {
        return [
            'id' => $incident->getId(),
            'type' => $incident->getType()->value,
            'type_label' => $incident->getType()->label(),
            'resolves_itself' => $incident->getType()->resolvesItself(),
            'item' => ['id' => $incident->getItemId(), 'name' => $incident->getItemName()],
            'warehouse' => ['id' => $incident->getWarehouseId(), 'name' => $incident->getWarehouseName()],
            'detail' => $incident->getDetail(),
            'metadata' => $incident->getMetadata(),
            'status' => $incident->getStatus()->value,
            'resolution' => $incident->getResolution(),
            'resolved_by' => $incident->getResolvedByName(),
            'resolved_at' => $incident->getResolvedAt(),
            'created_at' => $incident->getCreatedAt(),
        ];
    }
}
