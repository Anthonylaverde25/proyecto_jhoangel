<?php

declare(strict_types=1);

namespace App\Application\DTOs\Veterinary;

/**
 * ADR-13: the professional's act of closing the chain of custody. Nothing here comes from the
 * client except the observations — identity is taken from the resolved portal session, so a
 * forged payload cannot sign in somebody else's name.
 */
final readonly class SignExtractionActDTO
{
    public function __construct(
        public int $actId,
        public int $companyId,
        public int $veterinarianId,
        public ?string $observations = null,
        /** ADR-39: confirmed or corrected at signature, and frozen from then on. */
        public ?array $institution = null,
        public ?string $destinationPlan = null,
        public ?string $dispatchNoteNumber = null,
        public ?string $dispatchedAt = null,
        public ?int $signedByUserId = null,
        public ?int $accessTokenId = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            actId: (int) ($data['act_id'] ?? 0),
            companyId: (int) ($data['company_id'] ?? 0),
            veterinarianId: (int) ($data['veterinarian_id'] ?? 0),
            observations: isset($data['observations']) ? (string) $data['observations'] : null,
            institution: isset($data['institution']) && is_array($data['institution'])
                ? $data['institution']
                : null,
            destinationPlan: isset($data['destination_plan']) ? (string) $data['destination_plan'] : null,
            dispatchNoteNumber: isset($data['dispatch_note_number']) && $data['dispatch_note_number'] !== null
                ? (string) $data['dispatch_note_number']
                : null,
            dispatchedAt: isset($data['dispatched_at']) && $data['dispatched_at'] !== null
                ? (string) $data['dispatched_at']
                : null,
            signedByUserId: isset($data['signed_by_user_id']) && $data['signed_by_user_id'] !== null
                ? (int) $data['signed_by_user_id']
                : null,
            accessTokenId: isset($data['access_token_id']) && $data['access_token_id'] !== null
                ? (int) $data['access_token_id']
                : null
        );
    }
}
