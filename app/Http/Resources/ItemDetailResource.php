<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\ItemEntity;
use App\Core\Entities\ItemProviderLinkEntity;
use Illuminate\Http\Request;

/**
 * @property-read ItemEntity $resource
 */
class ItemDetailResource extends ItemResource
{
    /** @var array{has_movements: bool, last_purchases: array<int, array<string, mixed>>} */
    private array $usage = ['has_movements' => false, 'last_purchases' => []];

    /** @param array{has_movements: bool, last_purchases: array<int, array<string, mixed>>} $usage */
    public function withUsage(array $usage): static
    {
        $this->usage = $usage;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'has_movements' => $this->usage['has_movements'],
            'presentations' => ItemPresentationResource::collection($this->resource->getPresentations())->resolve($request),
            'providers' => array_map(fn (ItemProviderLinkEntity $link) => [
                'provider_id' => $link->getProviderId(),
                'name' => $link->getProviderName(),
                'cuit' => $link->getProviderCuit(),
                'lead_time_days' => $link->getLeadTimeDays(),
                'is_preferred' => $link->isPreferred(),
                'last_purchase' => $this->usage['last_purchases'][$link->getProviderId()] ?? null,
            ], $this->resource->getProviderLinks()),
        ];
    }
}
