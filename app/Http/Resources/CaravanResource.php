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
            // Null while a calf born here waits for its tag; `label` is what the screens show.
            'identification' => $this->resource->findIdentification()?->getValue(),
            'label'          => $this->resource->label(),
            'is_tagged'      => $this->resource->isTagged(),
            'tagged_at'      => $this->resource->getTaggedAt(),
            'is_dead'        => $this->resource->isDead(),
            'death'          => $this->resource->getDeath() !== null ? [
                'date' => $this->resource->getDeath()->date,
                'cause' => $this->resource->getDeath()->causeName,
                'observations' => $this->resource->getDeath()->observations,
            ] : null,
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
            'is_breeding_eligible' => $this->resource->getBreedingEligibility()?->isEligible ?? true,
            'breeding_exclusion_reason' => $this->resource->getBreedingEligibility()?->reason?->value,
            'breeding_exclusion_reason_label' => $this->resource->getBreedingEligibility()?->reason?->label(),
            'is_reproductive_category' => $this->resource->isReproductiveCategory(),
            'reproductive_status' => \App\Core\Enums\ReproductiveStatus::of(
                $this->resource->isReproductiveCategory(),
                $this->resource->getBreedingEligibility()?->isEligible ?? true
            )->value,
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
                'gestation_stage' => $this->resource->getActiveGestation()->getGestationStage()?->value,
                'gestation_months' => $this->resource->getActiveGestation()->getGestationMonths(),
                'is_current' => $this->resource->getActiveGestation()->isCurrent(),
                'success' => $this->resource->getActiveGestation()->getSuccess(),
                'notes' => $this->resource->getActiveGestation()->getNotes(),
                // An N reported she passed her due date without calving: open while the gestation is.
                'calving_overdue_reported_at' => $this->resource->getActiveGestation()->getCalvingOverdueReportedAt(),
                'calving_overdue_days' => $this->resource->getActiveGestation()->isCalvingOverdue()
                    ? BirthOrderResource::daysSince((string) $this->resource->getActiveGestation()->getCalvingOverdueReportedAt())
                    : null,
                'sires' => array_map(fn($sire) => [
                    'id' => $sire->getSireId(),
                    'identification' => $sire->getSireIdentification(),
                    'is_confirmed' => $sire->isConfirmed(),
                ], $this->resource->getActiveGestation()->getSires()),
            ] : null,
            // Calves born dead are charged to the mother; those that died at foot are not.
            'stillborn_count' => count($this->resource->getStillbirths()),
            'last_stillborn_date' => ($this->resource->getStillbirths()[0] ?? null)?->getEndDate(),
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
                // A calf of her own at foot, not whether she nurses herself.
                'is_nursing' => $this->resource->hasCalfAtFoot(),
                'nursing_calves_count' => $this->resource->getNursingCalvesCount() ?? 0,
                'gestation_stage' => $this->resource->getGestationStage()?->value,
                'gestation_months' => $this->resource->getGestationMonths(),
            ] : null,
        ];
    }
}

