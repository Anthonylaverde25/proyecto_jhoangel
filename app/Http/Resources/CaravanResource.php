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
            'category'       => $this->resource->getCategoryName() ?? ($this->resource->getCategoryCode() ? strtolower($this->resource->getCategoryCode()) : null),
            'category_id'    => $this->resource->getCategoryId(),
            'category_code'  => $this->resource->getCategoryCode(),
            'category_name'  => $this->resource->getCategoryName(),
            'subcategory_id' => $this->resource->getSubcategoryId(),
            'subcategory_code' => $this->resource->getSubcategoryCode(),
            'subcategory_name' => $this->resource->getSubcategoryName(),
            'teeth'          => $this->resource->getTeeth(),
            'entry_weight'   => $this->resource->getEntryWeight(),
            'exit_weight'    => $this->resource->getExitWeight(),
            'breed'          => $this->resource->getBreedName(),
            'breed_id'       => $this->resource->getBreedId(),
            'color_id'       => $this->resource->getColorId(),
            'color'          => $this->resource->getColorName(),
            'sex'            => $this->resource->getSex()?->value,
            'entry_date'     => $this->resource->getCreatedAt()?->format('m/Y'),
            'renspa'         => $this->resource->getRenspa(),
            'provider_id'    => $this->resource->getProviderId(),
            'provider_name'  => $this->resource->getProviderName(),
            'provenance'     => $this->resource->getProvenance()?->toArray(),
            'is_operational' => $this->resource->getBatchId() !== null && $this->resource->getProviderId() === null,
            'batch'          => [
                'id'   => $this->resource->getBatchId(),
                'name' => $this->resource->getBatchName(),
                'farm_name' => $this->resource->getFarmName(),
            ],
            'current_weight' => $this->resource->getCurrentWeight() ?? $this->resource->getEntryWeight(),
            'female_details' => $this->resource->getReproductiveDetails() ? [
                'is_empty' => $this->resource->getReproductiveDetails()->isEmpty(),
                'arrival_category' => $this->resource->getReproductiveDetails()->getArrivalCategory()->value,
            ] : null,
            'active_gestation' => $this->resource->getActiveGestation() ? [
                'id' => $this->resource->getActiveGestation()->getId(),
                'start_date' => $this->resource->getActiveGestation()->getStartDate(),
                'estimated_due_date' => $this->resource->getActiveGestation()->getEstimatedDueDate(),
                'gestation_stage' => $this->resource->getActiveGestation()->getGestationStage()->value,
                'gestation_months' => $this->resource->getActiveGestation()->getGestationMonths(),
                'is_current' => $this->resource->getActiveGestation()->isCurrent(),
                'success' => $this->resource->getActiveGestation()->getSuccess(),
                'notes' => $this->resource->getActiveGestation()->getNotes(),
                'sires' => array_map(fn($sire) => [
                    'id' => $sire->getSireId(),
                    'identification' => $sire->getSireIdentification(),
                    'is_confirmed' => $sire->isConfirmed(),
                ], $this->resource->getActiveGestation()->getSires()),
            ] : null,
            'lineage' => $this->resource->getLineage() ? [
                'mother_id' => $this->resource->getLineage()->getMotherId(),
                'mother_identification' => $this->resource->getLineage()->getMotherIdentification(),
                'father_id' => $this->resource->getLineage()->getFatherId(),
                'father_identification' => $this->resource->getLineage()->getFatherIdentification(),
                'birth_date' => $this->resource->getLineage()->getBirthDate(),
                'is_nursing' => $this->resource->getLineage()->isNursing(),
                'sire_assigned_at' => $this->resource->getLineage()->getSireAssignedAt()?->format('Y-m-d H:i:s'),
                'sire_identification_method' => $this->resource->getLineage()->getSireIdentificationMethod(),
                'sire_notes' => $this->resource->getLineage()->getSireNotes(),
            ] : null,
            'physiological_state' => $this->resource->getSex()?->value === 'H' ? [
                'code' => $this->resource->getPhysiologicalState()->value,
                'label' => $this->resource->getPhysiologicalState()->label(),
                'is_pregnant' => $this->resource->isPregnant(),
                'is_nursing' => $this->resource->isNursing(),
                'gestation_stage' => $this->resource->getGestationStage()?->value,
                'gestation_months' => $this->resource->getGestationMonths(),
            ] : null,
        ];
    }
}

