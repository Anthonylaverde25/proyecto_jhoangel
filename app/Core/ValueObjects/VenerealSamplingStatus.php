<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use DateTimeImmutable;

/**
 * Aggregated venereal sampling picture of one bull, built by the repository out of
 * `bull_lab_samples` and consumed by BullHealthEvaluationEngine (ADR-4).
 *
 * The rule it encodes: a bull is cleared only when EVERY required pathogen accumulates
 * the required number of negative rounds, each within the validity window and each taken
 * after the last positive result for that pathogen.
 */
final class VenerealSamplingStatus
{
    /** @var array<string, list<SampleResult>> */
    private array $byPathogenCode = [];

    /**
     * @param list<SampleResult> $samples
     */
    public function __construct(
        array $samples,
        private readonly DateTimeImmutable $referenceDate = new DateTimeImmutable('today')
    ) {
        foreach ($samples as $sample) {
            $this->byPathogenCode[$sample->pathogenCode][] = $sample;
        }
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function hasAnySample(): bool
    {
        return $this->byPathogenCode !== [];
    }

    public function hasPendingResults(): bool
    {
        foreach ($this->byPathogenCode as $samples) {
            foreach ($samples as $sample) {
                if ($sample->isPending()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A positive result that no later negative round has superseded.
     */
    public function hasStandingPositive(): bool
    {
        foreach (array_keys($this->byPathogenCode) as $pathogenCode) {
            $lastPositiveDate = $this->lastPositiveDate((string) $pathogenCode);

            if ($lastPositiveDate === null) {
                continue;
            }

            if (!$this->hasNegativeAfter((string) $pathogenCode, $lastPositiveDate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Distinct negative sample rounds inside the validity window and posterior to the
     * last positive result for that pathogen.
     */
    public function negativeRoundsFor(string $pathogenCode, int $validityDays): int
    {
        $samples = $this->byPathogenCode[$pathogenCode] ?? [];

        if ($samples === []) {
            return 0;
        }

        $windowStart = $this->referenceDate->modify(sprintf('-%d days', $validityDays));
        $lastPositiveDate = $this->lastPositiveDate($pathogenCode);

        $rounds = [];

        foreach ($samples as $sample) {
            if (!$sample->isNegative()) {
                continue;
            }

            if ($sample->sampleDate < $windowStart) {
                continue;
            }

            if ($lastPositiveDate !== null && $sample->sampleDate <= $lastPositiveDate) {
                continue;
            }

            $rounds[$sample->round] = true;
        }

        return count($rounds);
    }

    /**
     * @param list<string> $requiredPathogenCodes
     */
    public function isClearedFor(array $requiredPathogenCodes, int $requiredRounds, int $validityDays): bool
    {
        foreach ($requiredPathogenCodes as $pathogenCode) {
            if ($this->negativeRoundsFor($pathogenCode, $validityDays) < $requiredRounds) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, int> Negative rounds accumulated per required pathogen, for UI feedback.
     * @param list<string> $requiredPathogenCodes
     */
    public function negativeRoundsBreakdown(array $requiredPathogenCodes, int $validityDays): array
    {
        $breakdown = [];

        foreach ($requiredPathogenCodes as $pathogenCode) {
            $breakdown[$pathogenCode] = $this->negativeRoundsFor($pathogenCode, $validityDays);
        }

        return $breakdown;
    }

    private function lastPositiveDate(string $pathogenCode): ?DateTimeImmutable
    {
        $last = null;

        foreach ($this->byPathogenCode[$pathogenCode] ?? [] as $sample) {
            if ($sample->isPositive() && ($last === null || $sample->sampleDate > $last)) {
                $last = $sample->sampleDate;
            }
        }

        return $last;
    }

    private function hasNegativeAfter(string $pathogenCode, DateTimeImmutable $date): bool
    {
        foreach ($this->byPathogenCode[$pathogenCode] ?? [] as $sample) {
            if ($sample->isNegative() && $sample->sampleDate > $date) {
                return true;
            }
        }

        return false;
    }
}
