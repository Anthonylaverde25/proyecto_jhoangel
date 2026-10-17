<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\ValueObjects\CaravanSummaryRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * High-performance, lightweight Resource for paginated list and grid views.
 * Avoids deep lineage/gestation tree hydration for fast inventory display.
 *
 * @property CaravanSummaryRow $resource
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
        $row = $this->resource;
        $categoryName = $row->categoryName ?? ($row->categoryId !== null ? (string) $row->categoryId : null);

        return [
            'id'                => $row->id,
            'identification'    => $row->identification,
            'category'          => $categoryName,
            'category_id'       => $row->categoryId,
            'category_name'     => $categoryName,
            'subcategory_id'    => $row->subcategoryId,
            'subcategory_name'  => $row->subcategoryName,
            'teeth'             => $row->teeth,
            'entry_weight'      => $row->entryWeight,
            'current_weight'    => $row->currentWeight,
            'breed'             => $row->breedName,
            'breed_id'          => $row->breedId,
            'sex'               => $row->sex,
            'entry_date'        => $row->entryDate,
            'batch_id'          => $row->batchId,
            'batch_name'        => $row->batchName,
            'farm_name'         => $row->farmName,
            'batch_assigned_at' => $row->batchAssignedAt?->format('Y-m-d'),
            'gestation_status'  => $row->gestationStatus,
            'is_breeding_eligible' => $row->isBreedingEligible,
            'breeding_exclusion_reason' => $row->breedingExclusionReason,
            'breeding_exclusion_reason_label' => \App\Core\Enums\BreedingExclusionReason::tryFrom((string) $row->breedingExclusionReason)?->label(),
            'is_reproductive_category' => $row->isReproductiveCategory,
            'reproductive_status' => \App\Core\Enums\ReproductiveStatus::of($row->isReproductiveCategory, $row->isBreedingEligible)->value,
        ];
    }
}
