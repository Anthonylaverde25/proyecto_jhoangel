<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Entities\VeterinaryDiagnosisEntity;
use App\Core\Enums\ReproductiveAptitudeStatus;
use App\Core\ValueObjects\VenerealSamplingStatus;

/**
 * Zootechnical aptitude engine, grounded in Carrillo (1988) "Manejo de un Rodeo de Cría",
 * pp. 165-173, 207.
 *
 * Rules are evaluated in strict precedence order: the first matching condition determines
 * the status and stops the evaluation.
 */
final class BullHealthEvaluationEngine
{
    /**
     * @param list<string> $requiredPathogenCodes Venereal pathogens that must be cleared.
     */
    public function __construct(
        private readonly array $requiredPathogenCodes = ['TRITRICHOMONAS_FOETUS', 'CAMPYLOBACTER_FETUS'],
        private readonly int $requiredNegativeRounds = 2,
        private readonly int $validityDays = 365,
        private readonly bool $enforceSampling = true,
        private readonly float $minScrotalCircumferenceCm = 28.0,
        private readonly float $minBodyConditionScore = 2.0
    ) {
    }

    /**
     * Build the engine from `config/livestock.php` so the sanitary rules stay auditable and
     * adjustable without redeploying logic (ADR-4).
     */
    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $venereal */
        $venereal = (array) config('livestock.venereal', []);
        /** @var array<string, mixed> $biometry */
        $biometry = (array) config('livestock.biometry', []);

        return new self(
            requiredPathogenCodes: array_values((array) ($venereal['required_pathogen_codes'] ?? [])),
            requiredNegativeRounds: (int) ($venereal['required_negative_rounds'] ?? 2),
            validityDays: (int) ($venereal['validity_days'] ?? 365),
            enforceSampling: (bool) ($venereal['enforce_sampling'] ?? true),
            minScrotalCircumferenceCm: (float) ($biometry['min_scrotal_circumference_cm'] ?? 28.0),
            minBodyConditionScore: (float) ($biometry['min_body_condition_score'] ?? 2.0)
        );
    }

    /**
     * Compute reproductive aptitude from the physical examination, the active veterinary
     * diagnoses and the accumulated venereal sampling.
     *
     * @param array<VeterinaryDiagnosisEntity> $activeDiagnoses
     */
    public function computeAptitude(
        ?float $scrotalCircumferenceCm,
        ?float $bodyConditionScore,
        ?string $aplomoNotes,
        array $activeDiagnoses,
        ?VenerealSamplingStatus $sampling = null
    ): ReproductiveAptitudeStatus {
        $sampling ??= VenerealSamplingStatus::empty();

        // 1. Fail-fast: active disqualifying pathogen (Trichomoniasis, Campylobacteriosis, Brucellosis).
        foreach ($activeDiagnoses as $diagnosis) {
            if ($diagnosis->isActive() && $diagnosis->isPathogenDisqualifying()) {
                return ReproductiveAptitudeStatus::UNFIT;
            }
        }

        // 2. Standing positive determination not superseded by a later negative round (ADR-4).
        if ($sampling->hasStandingPositive()) {
            return ReproductiveAptitudeStatus::UNFIT;
        }

        // 3. Treatable infections or injuries currently under treatment (foot rot, keratitis).
        foreach ($activeDiagnoses as $diagnosis) {
            if ($diagnosis->isInTreatment()) {
                return ReproductiveAptitudeStatus::IN_TREATMENT;
            }
        }

        // 4. Biometrical rules (Carrillo p. 170).
        if ($scrotalCircumferenceCm !== null && $scrotalCircumferenceCm < $this->minScrotalCircumferenceCm) {
            return ReproductiveAptitudeStatus::UNFIT;
        }

        if ($bodyConditionScore !== null && $bodyConditionScore < $this->minBodyConditionScore) {
            return ReproductiveAptitudeStatus::UNFIT;
        }

        // 5. Severe locomotor disqualification recorded in the aplomo notes.
        if ($aplomoNotes !== null && $this->hasDisqualifyingAplomo($aplomoNotes)) {
            return ReproductiveAptitudeStatus::UNFIT;
        }

        if ($this->enforceSampling) {
            // 6. Results still awaited from the laboratory.
            if ($sampling->hasPendingResults()) {
                return ReproductiveAptitudeStatus::PENDING_EVALUATION;
            }

            // 7. Not enough negative rounds for every required venereal pathogen.
            if (!$sampling->isClearedFor($this->requiredPathogenCodes, $this->requiredNegativeRounds, $this->validityDays)) {
                return ReproductiveAptitudeStatus::PENDING_EVALUATION;
            }
        }

        // 8. No biometry has ever been recorded: nothing to certify yet.
        if ($scrotalCircumferenceCm === null && $bodyConditionScore === null && empty($aplomoNotes)) {
            return ReproductiveAptitudeStatus::PENDING_EVALUATION;
        }

        // 9. Healthy, evaluated, sampled and adequate.
        return ReproductiveAptitudeStatus::APT;
    }

    private function hasDisqualifyingAplomo(string $aplomoNotes): bool
    {
        $normalized = mb_strtolower($aplomoNotes);

        return str_contains($normalized, 'descarte')
            || str_contains($normalized, 'artritis severa')
            || str_contains($normalized, 'renguera cronica')
            || str_contains($normalized, 'renguera crónica')
            || str_contains($normalized, 'tarsos vencidos graves');
    }
}
