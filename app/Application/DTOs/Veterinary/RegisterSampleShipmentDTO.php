<?php

declare(strict_types=1);

namespace App\Application\DTOs\Veterinary;

/**
 * ADR-30: what the professional declares having dispatched. No act id — a cooler is not part of
 * one chute session (ADR-36); the tubes it carries say which acts it covers.
 */
final readonly class RegisterSampleShipmentDTO
{
    /**
     * @param array<string, mixed> $institution
     * @param list<int> $sampleIds
     */
    public function __construct(
        public int $companyId,
        public int $veterinarianId,
        public string $declaredByName,
        public string $shippedOn,
        public array $institution,
        public bool $coldChainOk,
        public array $sampleIds,
        public ?string $conditionNotes = null,
        public ?int $declaredByUserId = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            companyId: (int) ($data['company_id'] ?? 0),
            veterinarianId: (int) ($data['veterinarian_id'] ?? 0),
            declaredByName: (string) ($data['declared_by_name'] ?? ''),
            shippedOn: (string) ($data['shipped_on'] ?? date('Y-m-d')),
            institution: (array) ($data['institution'] ?? []),
            coldChainOk: (bool) ($data['cold_chain_ok'] ?? true),
            sampleIds: array_values(array_unique(array_map('intval', (array) ($data['sample_ids'] ?? [])))),
            conditionNotes: isset($data['condition_notes']) && $data['condition_notes'] !== null
                ? (string) $data['condition_notes']
                : null,
            declaredByUserId: isset($data['declared_by_user_id']) && $data['declared_by_user_id'] !== null
                ? (int) $data['declared_by_user_id']
                : null
        );
    }
}
