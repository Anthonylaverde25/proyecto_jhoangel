<?php

declare(strict_types=1);

namespace App\Application\DTOs\Veterinary;

/**
 * ADR-11: what the laboratory states about the tubes an extraction act put in its hands.
 * A separate document, with its own number and its own signature.
 */
final readonly class RegisterLabReportDTO
{
    /**
     * @param list<LabReportLineDTO> $lines
     * @param list<ProtocolAttachmentUploadDTO> $attachments ADR-27: the laboratory's own PDF.
     */
    public function __construct(
        public int $extractionActId,
        public int $companyId,
        public int $veterinarianId,
        public string $labReportNumber,
        public string $resultDate,
        public array $lines,
        /** ADR-29: the centre the professional reports from. Described, not picked from a catalogue. */
        public ?array $reportingInstitution = null,
        /** ADR-31 (rev.): declared by the professional, never deduced from a CUIT. */
        public bool $isDerived = false,
        /** ADR-31 (rev.): the third party that ran the assay. Only meaningful on a derivation. */
        public ?array $analysingInstitution = null,
        public ?string $observations = null,
        public ?int $createdByUserId = null,
        public ?int $accessTokenId = null,
        public array $attachments = []
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $lines = [];

        foreach ((array) ($data['lines'] ?? []) as $line) {
            $lines[] = LabReportLineDTO::fromArray((array) $line);
        }

        // Already read out of the HTTP layer by the controller, so the use case never depends
        // on `Illuminate\Http\UploadedFile`.
        $attachments = [];

        foreach ((array) ($data['attachments'] ?? []) as $attachment) {
            if ($attachment instanceof ProtocolAttachmentUploadDTO) {
                $attachments[] = $attachment;
            }
        }

        return new self(
            extractionActId: (int) ($data['extraction_act_id'] ?? 0),
            companyId: (int) ($data['company_id'] ?? 0),
            veterinarianId: (int) ($data['veterinarian_id'] ?? 0),
            labReportNumber: trim((string) ($data['lab_report_number'] ?? '')),
            resultDate: (string) ($data['result_date'] ?? date('Y-m-d')),
            lines: $lines,
            reportingInstitution: isset($data['reporting_institution']) && is_array($data['reporting_institution'])
                ? $data['reporting_institution']
                : null,
            isDerived: (bool) ($data['is_derived'] ?? false),
            analysingInstitution: isset($data['analysing_institution']) && is_array($data['analysing_institution'])
                ? $data['analysing_institution']
                : null,
            observations: isset($data['observations']) ? (string) $data['observations'] : null,
            createdByUserId: isset($data['created_by_user_id']) && $data['created_by_user_id'] !== null
                ? (int) $data['created_by_user_id']
                : null,
            accessTokenId: isset($data['access_token_id']) && $data['access_token_id'] !== null
                ? (int) $data['access_token_id']
                : null,
            attachments: $attachments
        );
    }

    /**
     * @return list<int>
     */
    public function sampleIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (LabReportLineDTO $line): int => $line->sampleId,
            $this->lines
        )));
    }
}
