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
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'presentations' => ItemPresentationResource::collection($this->resource->getPresentations())->resolve($request),
            'providers' => array_map(fn (ItemProviderLinkEntity $link) => [
                'provider_id' => $link->getProviderId(),
                'name' => $link->getProviderName(),
                'cuit' => $link->getProviderCuit(),
                'lead_time_days' => $link->getLeadTimeDays(),
                'is_preferred' => $link->isPreferred(),
            ], $this->resource->getProviderLinks()),
        ];
    }
}
