<?php

declare(strict_types=1);

namespace App\Application\DTOs\Veterinary;

/**
 * Request to mint a temporary portal link for an external professional or health centre.
 */
final readonly class IssueVeterinaryPortalTokenDTO
{
    public function __construct(
        public int $companyId,
        public int $veterinarianId,
        public int $ttlHours,
        public ?int $batchId = null,
        public ?int $diagnosticProtocolId = null,
        public ?string $label = null,
        public ?int $maxUses = null,
        public ?int $createdByUserId = null,
        public bool $sendEmail = false,
        public ?string $recipientEmail = null,
        public ?string $senderNote = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $defaultTtl = (int) config('livestock.veterinary_portal.default_ttl_hours', 72);

        return new self(
            companyId: (int) ($data['company_id'] ?? 0),
            veterinarianId: (int) ($data['veterinarian_id'] ?? 0),
            ttlHours: (int) ($data['ttl_hours'] ?? $defaultTtl),
            batchId: isset($data['batch_id']) && $data['batch_id'] !== null ? (int) $data['batch_id'] : null,
            diagnosticProtocolId: isset($data['diagnostic_protocol_id']) && $data['diagnostic_protocol_id'] !== null
                ? (int) $data['diagnostic_protocol_id']
                : null,
            label: isset($data['label']) ? (string) $data['label'] : null,
            maxUses: isset($data['max_uses']) && $data['max_uses'] !== null ? (int) $data['max_uses'] : null,
            createdByUserId: isset($data['created_by_user_id']) && $data['created_by_user_id'] !== null ? (int) $data['created_by_user_id'] : null,
            sendEmail: (bool) ($data['send_email'] ?? false),
            recipientEmail: isset($data['recipient_email']) && $data['recipient_email'] !== null
                ? trim((string) $data['recipient_email'])
                : null,
            senderNote: isset($data['sender_note']) && $data['sender_note'] !== null
                ? trim((string) $data['sender_note'])
                : null
        );
    }
}
