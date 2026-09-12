<?php

declare(strict_types=1);

namespace App\Application\DTOs\Veterinary;

/**
 * Use Case 1 payload: the professional loads biometry and sampling straight from the chute
 * and signs the report with their license number.
 */
final readonly class ProcessVetPortalEvaluationDTO
{
    /**
     * @param list<PortalBullEvaluationLineDTO> $bulls
     */
    public function __construct(
        public int $companyId,
        public int $veterinarianId,
        public int $batchId,
        public string $protocolNumber,
        public string $sampleDate,
        public string $resultDate,
        public array $bulls,
        public ?string $observations = null,
        public ?int $createdByUserId = null,
        public ?int $accessTokenId = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $bulls = [];

        foreach ((array) ($data['bulls'] ?? []) as $bull) {
            $bulls[] = PortalBullEvaluationLineDTO::fromArray((array) $bull);
        }

        return new self(
            companyId: (int) ($data['company_id'] ?? 0),
            veterinarianId: (int) ($data['veterinarian_id'] ?? 0),
            batchId: (int) ($data['batch_id'] ?? 0),
            protocolNumber: trim((string) ($data['protocol_number'] ?? '')),
            sampleDate: (string) ($data['sample_date'] ?? date('Y-m-d')),
            resultDate: (string) ($data['result_date'] ?? date('Y-m-d')),
            bulls: $bulls,
            observations: isset($data['observations']) ? (string) $data['observations'] : null,
            createdByUserId: isset($data['created_by_user_id']) && $data['created_by_user_id'] !== null ? (int) $data['created_by_user_id'] : null,
            accessTokenId: isset($data['access_token_id']) && $data['access_token_id'] !== null ? (int) $data['access_token_id'] : null
        );
    }

    /**
     * @return list<int>
     */
    public function caravanIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (PortalBullEvaluationLineDTO $line): int => $line->caravanId,
            $this->bulls
        )));
    }
}
