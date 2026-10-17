<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Core\Entities\WeighingTypeEntity $resource
 */
class WeighingTypeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->resource->getId(),
            'code'                     => $this->resource->getCode()->value,
            'name'                     => $this->resource->getName(),
            'description'              => $this->resource->getDescription(),
            'affects_adpv'             => $this->resource->affectsAdpv(),
            'default_method'           => $this->resource->getDefaultMethod()->value,
            'default_shrunk_condition' => $this->resource->getDefaultShrunkCondition()->value,
            'allows_individual'        => $this->resource->getCode()->allowsIndividualWeighing(),
            'allows_group'             => $this->resource->getCode()->allowsGroupWeighing(),
            'display_order'            => $this->resource->getDisplayOrder(),
            'is_system'                => $this->resource->isSystem(),
            'is_active'                => $this->resource->isActive(),
        ];
    }
}
