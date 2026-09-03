<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\BullHealthEvaluationEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BullHealthEvaluationEntity
 */
class BullHealthEvaluationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BullHealthEvaluationEntity $this */
        return [
            'id' => $this->getId(),
            'company_id' => $this->getCompanyId(),
            'caravan_id' => $this->getCaravanId(),
            'caravan_number' => $this->getCaravanNumber(),
            'last_evaluation_date' => $this->getLastEvaluationDate()?->format('Y-m-d'),
            'aplomo_notes' => $this->getAplomoNotes(),
            'scrotal_circumference_cm' => $this->getScrotalCircumferenceCm(),
            'body_condition_score' => $this->getBodyConditionScore(),
            'libido' => $this->getLibido(),
            'status' => $this->getStatus()->value,
            'is_apt' => $this->isApt(),
            'is_under_treatment' => $this->isUnderTreatment(),
            'is_unfit' => $this->isUnfit(),
            'observations' => $this->getObservations(),
            'active_diagnoses' => VeterinaryDiagnosisResource::collection($this->getActiveDiagnoses()),
            'lab_samples' => BullLabSampleResource::collection($this->getLabSamples()),
        ];
    }
}
