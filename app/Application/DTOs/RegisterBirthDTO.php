<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final readonly class RegisterBirthDTO
{
    public function __construct(
        public string $calfIdentification,
        public string $calfSex,
        public ?string $calfCategory,
        public int $calfTeeth = 0,
        public ?float $calfWeight = null,
        public ?int $calfBreedId = null,
        public string $birthDate,
        public int $batchId,
        public int $motherId,
        public ?int $fatherId = null,
        public ?int $gestationId = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['calf_identification'] ?? ''),
            (string) ($data['calf_sex'] ?? ''),
            isset($data['calf_category']) ? (string) $data['calf_category'] : null,
            (int) ($data['calf_teeth'] ?? 0),
            isset($data['calf_weight']) ? (float) $data['calf_weight'] : null,
            isset($data['calf_breed_id']) ? (int) $data['calf_breed_id'] : null,
            (string) ($data['birth_date'] ?? date('Y-m-d')),
            (int) ($data['batch_id'] ?? 0),
            (int) ($data['mother_id'] ?? 0),
            isset($data['father_id']) ? (int) $data['father_id'] : null,
            isset($data['gestation_id']) ? (int) $data['gestation_id'] : null
        );
    }
}
