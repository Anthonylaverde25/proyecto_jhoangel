<?php

declare(strict_types=1);

namespace App\Application\DTOs\Veterinary;

use App\Core\Enums\LabSampleStatus;

/**
 * One determination: what a single tube yielded. The tube already exists — it was created and
 * labelled at the chute — so the laboratory resolves it rather than inventing a new row.
 */
final readonly class LabReportLineDTO
{
    public function __construct(
        public int $sampleId,
        public LabSampleStatus $status,
        public ?string $notes = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sampleId: (int) ($data['sample_id'] ?? 0),
            status: LabSampleStatus::from((string) ($data['status'] ?? LabSampleStatus::PENDING_RESULTS->value)),
            notes: isset($data['notes']) ? (string) $data['notes'] : null
        );
    }

    public function isResolved(): bool
    {
        return $this->status !== LabSampleStatus::PENDING_RESULTS;
    }
}
