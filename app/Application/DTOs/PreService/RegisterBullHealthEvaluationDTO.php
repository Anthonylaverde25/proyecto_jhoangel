<?php

declare(strict_types=1);

namespace App\Application\DTOs\PreService;

use App\Application\DTOs\Veterinary\CreateVeterinaryDiagnosisDTO;

final readonly class RegisterBullHealthEvaluationDTO
{
    public function __construct(
        public int $caravanId,
        public ?string $lastEvaluationDate = null,
        public ?string $aplomoNotes = null,
        public ?float $scrotalCircumferenceCm = null,
        public ?float $bodyConditionScore = null,
        public string $libido = 'MEDIA',
        public ?string $observations = null,
        public ?CreateVeterinaryDiagnosisDTO $diagnosis = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        $diagnosis = null;
        if (isset($data['diagnosis']) && is_array($data['diagnosis']) && !empty($data['diagnosis']['pathogen_id'])) {
            $diagnosisData = $data['diagnosis'];
            $diagnosisData['caravan_id'] = (int) ($data['caravan_id'] ?? 0);
            $diagnosis = CreateVeterinaryDiagnosisDTO::fromArray($diagnosisData);
        }

        return new self(
            caravanId: (int) ($data['caravan_id'] ?? 0),
            lastEvaluationDate: isset($data['last_evaluation_date']) ? (string) $data['last_evaluation_date'] : date('Y-m-d'),
            aplomoNotes: isset($data['aplomo_notes']) ? (string) $data['aplomo_notes'] : null,
            scrotalCircumferenceCm: isset($data['scrotal_circumference_cm']) && $data['scrotal_circumference_cm'] !== null ? (float) $data['scrotal_circumference_cm'] : null,
            bodyConditionScore: isset($data['body_condition_score']) && $data['body_condition_score'] !== null ? (float) $data['body_condition_score'] : null,
            libido: (string) ($data['libido'] ?? 'MEDIA'),
            observations: isset($data['observations']) ? (string) $data['observations'] : null,
            diagnosis: $diagnosis
        );
    }
}
