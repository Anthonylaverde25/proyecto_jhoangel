<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * High-performance, lightweight Resource for paginated list and grid views.
 * Avoids deep lineage/gestation tree hydration for fast inventory display.
 */
class CaravanSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $categoryName = $this->categoryRelation?->name ?? ($this->category_id ? (string) $this->category_id : null);
        $subcategoryName = $this->subcategoryRelation?->name ?? null;
        $breedName = $this->breedRelation?->name ?? null;
        $batch = $this->batch;
        $currentWeight = $this->currentWeight?->weight ?? $this->entry_weight;

        return [
            'id'               => $this->id,
            'identification'   => (string) $this->identification,
            'category'         => $categoryName,
            'category_id'      => $this->category_id,
            'category_name'    => $categoryName,
            'subcategory_id'   => $this->subcategory_id,
            'subcategory_name' => $subcategoryName,
            'teeth'            => $this->teeth,
            'entry_weight'     => $this->entry_weight !== null ? (float) $this->entry_weight : null,
            'current_weight'   => $currentWeight !== null ? (float) $currentWeight : null,
            'breed'            => $breedName,
            'breed_id'         => $this->breed_id,
            'sex'              => $this->sex instanceof \BackedEnum ? $this->sex->value : (string) $this->sex,
            'entry_date'       => $this->entry_date ? $this->entry_date->format('m/Y') : ($this->created_at ? $this->created_at->format('m/Y') : null),
            'batch_id'         => $this->batch_id,
            'batch_name'       => $batch?->name,
            'farm_name'        => $batch?->farm?->name,
        ];
    }
}
