<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\BreedEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read BreedEntity $resource
 */
class BreedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'   => $this->resource->getId(),
            'name' => $this->resource->getName(),
        ];
    }
}
