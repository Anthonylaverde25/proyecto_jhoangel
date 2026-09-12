<?php

declare(strict_types=1);

namespace App\Application\DTOs\Veterinary;

/**
 * Use Case 2 payload: the producer transcribes an external laboratory report and attaches
 * the original evidence (WhatsApp photo, letterheaded PDF).
 */
final readonly class CreateDiagnosticProtocolDTO
{
    /**
     * @param list<ProtocolSampleLineDTO> $samples
     * @param list<ProtocolAttachmentUploadDTO> $attachments
     */
    public function __construct(
        public int $companyId,
        public string $protocolNumber,
        public string $sampleDate,
        public string $resultDate,
        public string $sourceChannel,
        public array $samples,
        public array $attachments = [],
        public ?int $veterinarianId = null,
        public ?string $observations = null,
        public ?int $createdByUserId = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param list<ProtocolAttachmentUploadDTO> $attachments
     */
    public static function fromArray(array $data, array $attachments = []): self
    {
        $samples = [];

        foreach ((array) ($data['samples'] ?? []) as $line) {
            $samples[] = ProtocolSampleLineDTO::fromArray((array) $line);
        }

        return new self(
            companyId: (int) ($data['company_id'] ?? 0),
            protocolNumber: trim((string) ($data['protocol_number'] ?? '')),
            sampleDate: (string) ($data['sample_date'] ?? date('Y-m-d')),
            resultDate: (string) ($data['result_date'] ?? date('Y-m-d')),
            sourceChannel: (string) ($data['source_channel'] ?? 'OWNER_DIGITIZED'),
            samples: $samples,
            attachments: $attachments,
            veterinarianId: isset($data['veterinarian_id']) && $data['veterinarian_id'] !== null ? (int) $data['veterinarian_id'] : null,
            observations: isset($data['observations']) ? (string) $data['observations'] : null,
            createdByUserId: isset($data['created_by_user_id']) && $data['created_by_user_id'] !== null ? (int) $data['created_by_user_id'] : null
        );
    }

    /**
     * @return list<int>
     */
    public function caravanIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (ProtocolSampleLineDTO $line): int => $line->caravanId,
            $this->samples
        )));
    }
}
