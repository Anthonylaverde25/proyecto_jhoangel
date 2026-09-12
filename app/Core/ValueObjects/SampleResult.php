<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Enums\LabSampleStatus;
use DateTimeImmutable;

/**
 * A single laboratory determination, stripped of any persistence concern so the
 * aptitude engine never sees an Eloquent model (AGENT.md 5.A).
 */
final readonly class SampleResult
{
    public function __construct(
        public string $pathogenCode,
        public int $round,
        public DateTimeImmutable $sampleDate,
        public LabSampleStatus $status
    ) {
    }

    public function isNegative(): bool
    {
        return $this->status === LabSampleStatus::NEGATIVE_CLEARED;
    }

    public function isPositive(): bool
    {
        return $this->status === LabSampleStatus::POSITIVE_DETECTED;
    }

    public function isPending(): bool
    {
        return $this->status === LabSampleStatus::PENDING_RESULTS;
    }
}
