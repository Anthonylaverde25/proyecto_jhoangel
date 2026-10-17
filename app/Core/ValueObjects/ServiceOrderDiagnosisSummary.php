<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Enums\FemaleReproductiveVerdict;
use App\Core\Services\EmptyVerdictValidity;

/**
 * Where the pregnancy diagnosis of a service order stands, computed from the current verdict of
 * each female. Nothing here is stored: counters kept on the order would drift.
 *
 * A provisional empty (scanned while still exposed to the bulls) is not final: it counts as
 * pending and stays out of the rate.
 */
final class ServiceOrderDiagnosisSummary
{
    /**
     * @param int[] $pendingFemaleIds
     * @param int[] $provisionalEmptyFemaleIds
     */
    private function __construct(
        private readonly int $total,
        private readonly int $unchecked,
        private readonly int $pregnant,
        private readonly int $empty,
        private readonly int $provisionalEmpty,
        private readonly int $notDiagnosed,
        private readonly int $exited,
        private readonly array $pendingFemaleIds,
        private readonly array $provisionalEmptyFemaleIds
    ) {
    }

    /**
     * @param array<int, array{female_caravan_id: int, exposed_until: ?string, reproductive_status: ?string, diagnosed_at: ?string}> $females
     * @param string|null $exposureEndsOn closing day of the order: ends the exposures still open
     */
    public static function fromFemales(array $females, ?string $exposureEndsOn = null): self
    {
        $unchecked = $pregnant = $empty = $provisional = $notDiagnosed = $exited = 0;
        $pending = [];
        $provisionalIds = [];

        foreach ($females as $female) {
            $id = (int) $female['female_caravan_id'];
            $verdict = FemaleReproductiveVerdict::tryFrom((string) ($female['reproductive_status'] ?? ''))
                ?? FemaleReproductiveVerdict::UNCHECKED;

            switch ($verdict) {
                case FemaleReproductiveVerdict::PREGNANT:
                    $pregnant++;
                    break;
                case FemaleReproductiveVerdict::EMPTY:
                    if (EmptyVerdictValidity::isFinal($female['diagnosed_at'] ?? null, $female['exposed_until'] ?? null, $exposureEndsOn)) {
                        $empty++;
                    } else {
                        $provisional++;
                        $provisionalIds[] = $id;
                        $pending[] = $id;
                    }
                    break;
                case FemaleReproductiveVerdict::NOT_DIAGNOSED:
                    $notDiagnosed++;
                    break;
                case FemaleReproductiveVerdict::EXITED:
                    $exited++;
                    break;
                default:
                    $unchecked++;
                    $pending[] = $id;
            }
        }

        return new self(count($females), $unchecked, $pregnant, $empty, $provisional, $notDiagnosed, $exited, $pending, $provisionalIds);
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getPregnant(): int
    {
        return $this->pregnant;
    }

    public function getEmpty(): int
    {
        return $this->empty;
    }

    /** Females without a final verdict: unchecked plus provisional empties. */
    public function pendingCount(): int
    {
        return count($this->pendingFemaleIds);
    }

    /** @return int[] */
    public function getPendingFemaleIds(): array
    {
        return $this->pendingFemaleIds;
    }

    /** Females with a final verdict (or resolved without one). */
    public function evaluatedCount(): int
    {
        return $this->pregnant + $this->empty + $this->notDiagnosed + $this->exited;
    }

    /**
     * Pregnant over pregnant + final empty, in percent. Null while nobody has a final verdict.
     */
    public function pregnancyRate(): ?float
    {
        $denominator = $this->pregnant + $this->empty;
        if ($denominator === 0) {
            return null;
        }

        return round($this->pregnant * 100 / $denominator, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'unchecked' => $this->unchecked,
            'pregnant' => $this->pregnant,
            'empty' => $this->empty,
            'provisional_empty' => $this->provisionalEmpty,
            'not_diagnosed' => $this->notDiagnosed,
            'exited' => $this->exited,
            'evaluated' => $this->evaluatedCount(),
            'pending' => $this->pendingCount(),
            'pending_female_ids' => $this->pendingFemaleIds,
            'provisional_empty_female_ids' => $this->provisionalEmptyFemaleIds,
            'partial_rate' => $this->pregnancyRate(),
        ];
    }
}
