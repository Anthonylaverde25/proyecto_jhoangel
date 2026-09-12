<?php

declare(strict_types=1);

namespace App\Application\DTOs\Veterinary;

final readonly class VoidDiagnosticProtocolDTO
{
    public function __construct(
        public int $protocolId,
        public int $companyId,
        public string $reason,
        public ?int $voidedByUserId = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            protocolId: (int) ($data['protocol_id'] ?? 0),
            companyId: (int) ($data['company_id'] ?? 0),
            reason: trim((string) ($data['reason'] ?? '')),
            voidedByUserId: isset($data['voided_by_user_id']) && $data['voided_by_user_id'] !== null ? (int) $data['voided_by_user_id'] : null
        );
    }
}
