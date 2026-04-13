<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\CaravanEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read CaravanEntity $resource
 */
class CaravanResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->resource->getId(),
            'identification' => $this->resource->getIdentification()->getValue(),
            'category'       => $this->resource->getCategory()?->value,
            'teeth'          => $this->resource->getTeeth(),
            'entry_weight'   => $this->resource->getEntryWeight(),
            'exit_weight'    => $this->resource->getExitWeight(),
            'breed'          => $this->resource->getBreed(),
            'sex'            => $this->resource->getSex(),
            'entry_date'     => $this->resource->getCreatedAt()?->format('m/Y'),
        ];
    }
}
