<?php

declare(strict_types=1);

namespace App\Application\DTOs\Veterinary;

final readonly class ResolveVeterinaryDiagnosisDTO
{
    public function __construct(
        public int $diagnosisId,
        public ?string $resolutionDate = null,
        public ?string $notes = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            diagnosisId: (int) ($data['diagnosis_id'] ?? 0),
            resolutionDate: isset($data['resolution_date']) ? (string) $data['resolution_date'] : date('Y-m-d'),
            notes: isset($data['notes']) ? (string) $data['notes'] : null
        );
    }
}
