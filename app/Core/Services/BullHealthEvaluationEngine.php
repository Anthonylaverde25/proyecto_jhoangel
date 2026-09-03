<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Entities\VeterinaryDiagnosisEntity;
use App\Core\Enums\ReproductiveAptitudeStatus;

final class BullHealthEvaluationEngine
{
    /**
     * Compute reproductive aptitude based on physical examination and active veterinary diagnoses.
     * Grounded in Carrillo (1988) "Manejo de un Rodeo de Cría", pp. 165-173, 207.
     *
     * @param float|null $scrotalCircumferenceCm
     * @param float|null $bodyConditionScore
     * @param string|null $aplomoNotes
     * @param array<VeterinaryDiagnosisEntity> $activeDiagnoses
     * @return ReproductiveAptitudeStatus
     */
    public function computeAptitude(
        ?float $scrotalCircumferenceCm,
        ?float $bodyConditionScore,
        ?string $aplomoNotes,
        array $activeDiagnoses
    ): ReproductiveAptitudeStatus {
        // 1. Fail-Fast: Check for active disqualifying pathogens (Trichomoniasis, Campylobacteriosis, Brucellosis)
        foreach ($activeDiagnoses as $diagnosis) {
            if ($diagnosis->isActive() && $diagnosis->isPathogenDisqualifying()) {
                return ReproductiveAptitudeStatus::UNFIT;
            }
        }

        // 2. Check for active treatable infections/injuries in treatment (Foot rot/Pietín, Keratitis)
        foreach ($activeDiagnoses as $diagnosis) {
            if ($diagnosis->isInTreatment()) {
                return ReproductiveAptitudeStatus::IN_TREATMENT;
            }
        }

        // 3. Biometrical rules (Carrillo p. 170)
        if ($scrotalCircumferenceCm !== null && $scrotalCircumferenceCm < 28.0) {
            return ReproductiveAptitudeStatus::UNFIT;
        }

        if ($bodyConditionScore !== null && $bodyConditionScore < 2.0) {
            return ReproductiveAptitudeStatus::UNFIT;
        }

        // 4. Locomotor severe disqualifications in aplomo notes if specified
        if ($aplomoNotes !== null) {
            $normalizedAplomo = mb_strtolower($aplomoNotes);
            if (
                str_contains($normalizedAplomo, 'descarte') ||
                str_contains($normalizedAplomo, 'artritis severa') ||
                str_contains($normalizedAplomo, 'renguera cronica') ||
                str_contains($normalizedAplomo, 'renguera crónica') ||
                str_contains($normalizedAplomo, 'tarsos vencidos graves')
            ) {
                return ReproductiveAptitudeStatus::UNFIT;
            }
        }

        // 5. If no measurements have ever been taken and no diagnoses
        if ($scrotalCircumferenceCm === null && $bodyConditionScore === null && empty($aplomoNotes)) {
            return ReproductiveAptitudeStatus::PENDING_EVALUATION;
        }

        // 6. Healthy, evaluated and adequate
        return ReproductiveAptitudeStatus::APT;
    }
}
