<?php

declare(strict_types=1);

namespace App\Application\DTOs\Paternity;

use App\Core\Enums\SireIdentificationMethod;

/** One calf whose sire the user confirms, and how the sire was identified. */
final readonly class ConfirmPaternityDTO
{
    public function __construct(
        public int $calfId,
        public int $fatherId,
        public SireIdentificationMethod $method,
        public ?string $notes = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $notes = isset($data['notes']) ? trim((string) $data['notes']) : '';

        return new self(
            calfId: (int) $data['calf_id'],
            fatherId: (int) $data['father_id'],
            method: SireIdentificationMethod::from((string) $data['method']),
            notes: $notes !== '' ? $notes : null
        );
    }
}
