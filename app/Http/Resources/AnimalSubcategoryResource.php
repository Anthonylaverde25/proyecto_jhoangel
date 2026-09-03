<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\AnimalSubcategoryEntity;
use App\Models\AnimalSubcategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnimalSubcategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof AnimalSubcategoryEntity) {
            return [
                'id' => $this->resource->getId(),
                'category_id' => $this->resource->getCategoryId(),
                'code' => $this->resource->getCode(),
                'name' => $this->resource->getName(),
                'target_weight_min' => $this->resource->getTargetWeightMin(),
                'target_weight_max' => $this->resource->getTargetWeightMax(),
                'description' => $this->resource->getDescription(),
            ];
        }

        /** @var AnimalSubcategory $this */
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'code' => $this->code,
            'name' => $this->name,
            'target_weight_min' => $this->target_weight_min !== null ? (float) $this->target_weight_min : null,
            'target_weight_max' => $this->target_weight_max !== null ? (float) $this->target_weight_max : null,
            'description' => $this->description,
        ];
    }
}
