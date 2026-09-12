<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\SampleShipmentEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SampleShipmentEntity
 */
class SampleShipmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SampleShipmentEntity $this */
        return [
            'id' => $this->getId(),
            'company_id' => $this->getCompanyId(),
            'shipped_on' => $this->getShippedOn()->format('Y-m-d'),

            // ADR-29: described, never a catalogue row.
            'institution' => $this->getInstitution()->jsonSerialize(),

            'cold_chain_ok' => $this->isColdChainOk(),
            'condition_notes' => $this->getConditionNotes(),

            // ADR-30: who witnessed the dispatch, frozen.
            'declared_by_veterinarian_id' => $this->getDeclaredByVeterinarianId(),
            'declared_by_name' => $this->getDeclaredByName(),
            'declared_at' => $this->getDeclaredAt()->format('Y-m-d H:i:s'),

            'samples_count' => $this->getSamplesCount(),
            // ADR-36: one cooler may cover several chute sessions.
            'acts_covered' => $this->getActsCovered(),
            'samples' => $this->getSamples(),

            // ADR-37: editable until a report cited it; after that, void and reissue.
            'is_voided' => $this->isVoided(),
            'void_reason' => $this->getVoidReason(),
            'voided_at' => $this->getVoidedAt()?->format('Y-m-d H:i:s'),
            'is_cited_by_report' => $this->isCitedByReport(),
            'is_editable' => $this->isEditable(),
        ];
    }
}
