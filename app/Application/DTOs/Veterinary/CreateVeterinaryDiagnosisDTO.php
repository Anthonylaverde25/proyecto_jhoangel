<?php

declare(strict_types=1);

namespace App\Application\DTOs\Veterinary;

final readonly class CreateVeterinaryDiagnosisDTO
{
    public function __construct(
        public int $caravanId,
        public int $pathogenId,
        public ?int $veterinarianId = null,
        public ?string $diagnosisDate = null,
        public string $status = 'IN_TREATMENT',
        public ?string $treatmentNotes = null,
        public string $sourceContext = 'PRE_SERVICE'
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            caravanId: (int) ($data['caravan_id'] ?? 0),
            pathogenId: (int) ($data['pathogen_id'] ?? 0),
            veterinarianId: isset($data['veterinarian_id']) && $data['veterinarian_id'] !== null ? (int) $data['veterinarian_id'] : null,
            diagnosisDate: isset($data['diagnosis_date']) ? (string) $data['diagnosis_date'] : date('Y-m-d'),
            status: (string) ($data['status'] ?? 'IN_TREATMENT'),
            treatmentNotes: isset($data['treatment_notes']) ? (string) $data['treatment_notes'] : null,
            sourceContext: (string) ($data['source_context'] ?? 'PRE_SERVICE')
        );
    }
}
