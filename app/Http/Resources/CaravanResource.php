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
            'breed_id'       => $this->resource->getBreedId(),
            'sex'            => $this->resource->getSex()?->value,
            'entry_date'     => $this->resource->getCreatedAt()?->format('m/Y'),
            'batch'          => [
                'id'   => $this->resource->getBatchId(),
                'name' => $this->resource->getBatchName(),
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
        ];
    }
}

