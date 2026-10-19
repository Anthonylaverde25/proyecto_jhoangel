<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\WarehouseEntity;
use App\Core\ValueObjects\WarehouseSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A warehouse and, in the list, what it holds (DD4).
 *
 * @property-read WarehouseEntity $resource
 */
class WarehouseResource extends JsonResource
{
    private ?WarehouseSummary $summary = null;

    public static function withSummary(WarehouseEntity $warehouse, WarehouseSummary $summary): self
    {
        $resource = new self($warehouse);
        $resource->summary = $summary;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $w = $this->resource;

        return [
            'id' => $w->id,
            'code' => $w->code,
            'name' => $w->name,
            'is_default' => $w->isDefault,
            'is_active' => $w->isActive,
            'summary' => $this->summary === null ? null : [
                'items_with_balance' => $this->summary->itemsWithBalance,
                'valued_value' => $this->summary->valuedValue,
                'items_with_unvalued' => $this->summary->itemsWithUnvalued,
                'pending_lots' => $this->summary->pendingLots,
                'negative_items' => $this->summary->negativeItems,
                'open_incidents' => $this->summary->openIncidents,
            ],
        ];
    }
}
