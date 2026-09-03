<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\AnimalCategoryEntity;
use App\Models\AnimalCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnimalCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof AnimalCategoryEntity) {
            return [
                'id' => $this->resource->getId(),
                'code' => $this->resource->getCode(),
                'name' => $this->resource->getName(),
                'sex' => $this->resource->getSex(),
                'min_age_months' => $this->resource->getMinAgeMonths(),
                'max_age_months' => $this->resource->getMaxAgeMonths(),
                'min_weight_kg' => $this->resource->getMinWeightKg(),
                'max_weight_kg' => $this->resource->getMaxWeightKg(),
                'is_reproductive' => $this->resource->isReproductive(),
                'description' => $this->resource->getDescription(),
                'subcategories' => AnimalSubcategoryResource::collection($this->resource->getSubcategories()),
            ];
        }

        /** @var AnimalCategory $this */
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'sex' => $this->sex,
            'min_age_months' => $this->min_age_months,
            'max_age_months' => $this->max_age_months,
            'min_weight_kg' => $this->min_weight_kg !== null ? (float) $this->min_weight_kg : null,
            'max_weight_kg' => $this->max_weight_kg !== null ? (float) $this->max_weight_kg : null,
            'is_reproductive' => (bool) $this->is_reproductive,
            'description' => $this->description,
            'subcategories' => AnimalSubcategoryResource::collection($this->whenLoaded('subcategories')),
        ];
    }
}
