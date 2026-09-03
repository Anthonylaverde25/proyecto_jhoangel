<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\VeterinaryDiagnosisEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VeterinaryDiagnosisEntity
 */
class VeterinaryDiagnosisResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var VeterinaryDiagnosisEntity $this */
        return [
            'id' => $this->getId(),
            'company_id' => $this->getCompanyId(),
            'caravan_id' => $this->getCaravanId(),
            'pathogen_id' => $this->getPathogenId(),
            'pathogen_code' => $this->getPathogenCode(),
            'pathogen_name' => $this->getPathogenName(),
            'pathogen_is_disqualifying' => $this->isPathogenDisqualifying(),
            'veterinarian_id' => $this->getVeterinarianId(),
            'veterinarian_name' => $this->getVeterinarianName(),
            'diagnosis_date' => $this->getDiagnosisDate()->format('Y-m-d'),
            'status' => $this->getStatus()->value,
            'is_active' => $this->isActive(),
            'resolution_date' => $this->getResolutionDate()?->format('Y-m-d'),
            'treatment_notes' => $this->getTreatmentNotes(),
            'source_context' => $this->getSourceContext(),
        ];
    }
}
