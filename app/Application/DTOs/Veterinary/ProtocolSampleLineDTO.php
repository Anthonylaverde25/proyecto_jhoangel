<?php

declare(strict_types=1);

namespace App\Application\DTOs\Veterinary;

/**
 * One cell of the results grid: what was determined for a given bull and pathogen in a
 * given sampling round. Negatives are persisted just like positives (ADR-1).
 */
final readonly class ProtocolSampleLineDTO
{
    public function __construct(
        public int $caravanId,
        public int $pathogenId,
        public string $sampleType,
        public int $sampleRound,
        public string $status,
        public ?string $tubeNumber = null,
        public ?string $notes = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            caravanId: (int) ($data['caravan_id'] ?? 0),
            pathogenId: (int) ($data['pathogen_id'] ?? 0),
            sampleType: (string) ($data['sample_type'] ?? 'PREPUCE_SCRAPE'),
            sampleRound: (int) ($data['sample_round'] ?? 1),
            status: (string) ($data['status'] ?? 'PENDING_RESULTS'),
            tubeNumber: isset($data['tube_number']) ? (string) $data['tube_number'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null
        );
    }

    public function isPositive(): bool
    {
        return $this->status === 'POSITIVE_DETECTED';
    }
}
