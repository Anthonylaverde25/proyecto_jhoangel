<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\ItemEntity;
use App\Application\Services\Stock\SemenStorageRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List shape of an item. ItemDetailResource adds presentations and providers. B2 adds the balance and
 * the VAT rate of its last invoice, when the controller passes them.
 *
 * @property-read ItemEntity $resource
 */
class ItemResource extends JsonResource
{
    /** @var array{quantity: string, unvalued_quantity: string, value: string, has_negative: bool, has_critical: bool}|null */
    private ?array $stock = null;

    private ?int $lastVatRateId = null;

    /** @param array{quantity: string, unvalued_quantity: string, value: string, has_negative: bool, has_critical: bool}|null $stock */
    public function withStock(?array $stock, ?int $lastVatRateId): static
    {
        $this->stock = $stock;
        $this->lastVatRateId = $lastVatRateId;

        return $this;
    }

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
            // Semen S-A (DA7): the screens show the lot column and the semen tab from it.
            'is_semen' => app(SemenStorageRule::class)->isSemen($this->resource),
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
            // Only stock-tracked articles have a balance; one never moved shows zero.
            'stock' => !$this->resource->tracksStock() ? null
                : ($this->stock ?? ['quantity' => '0.0000', 'unvalued_quantity' => '0.0000', 'value' => '0.0000', 'has_negative' => false, 'has_critical' => false, 'by_warehouse' => []]),
            'last_vat_rate_id' => $this->lastVatRateId,
        ];
    }
}
