<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Core\Enums\SireIdentificationMethod;

final readonly class AssignSireDTO
{
    public function __construct(
        public int $calfId,
        public int $fatherId,
        public string $identificationMethod,
        public ?string $sireNotes = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['calf_id'] ?? 0),
            (int) ($data['father_id'] ?? 0),
            (string) ($data['identification_method'] ?? SireIdentificationMethod::OPERATIONAL->value),
            isset($data['sire_notes']) ? (string) $data['sire_notes'] : null
        );
    }
}
