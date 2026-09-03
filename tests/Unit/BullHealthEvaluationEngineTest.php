<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Entities\VeterinaryDiagnosisEntity;
use App\Core\Enums\DiagnosisStatus;
use App\Core\Enums\ReproductiveAptitudeStatus;
use App\Core\Services\BullHealthEvaluationEngine;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class BullHealthEvaluationEngineTest extends TestCase
{
    private BullHealthEvaluationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new BullHealthEvaluationEngine();
    }

    public function test_disqualifying_active_pathogen_yields_unfit_status(): void
    {
        $trichomoniasis = new VeterinaryDiagnosisEntity(
            id: 1,
            companyId: 1,
            caravanId: 10,
            pathogenId: 1,
            veterinarianId: 2,
            diagnosisDate: new DateTimeImmutable('2026-09-01'),
            status: DiagnosisStatus::CONFIRMED_POSITIVE,
            pathogenCode: 'TRITRICHOMONAS_FOETUS',
            pathogenName: 'Tritrichomonas foetus',
            pathogenIsDisqualifying: true
        );

        $status = $this->engine->computeAptitude(
            scrotalCircumferenceCm: 36.0,
            bodyConditionScore: 3.5,
            aplomoNotes: 'Aplomos normales',
            activeDiagnoses: [$trichomoniasis]
        );

        $this->assertSame(ReproductiveAptitudeStatus::UNFIT, $status);
    }

    public function test_treatable_active_pathogen_in_treatment_yields_in_treatment_status(): void
    {
        $pietin = new VeterinaryDiagnosisEntity(
            id: 2,
            companyId: 1,
            caravanId: 10,
            pathogenId: 5,
            veterinarianId: 2,
            diagnosisDate: new DateTimeImmutable('2026-09-01'),
            status: DiagnosisStatus::IN_TREATMENT,
            pathogenCode: 'FUSOBACTERIUM_NECROPHORUM',
            pathogenName: 'Pietín',
            pathogenIsDisqualifying: false
        );

        $status = $this->engine->computeAptitude(
            scrotalCircumferenceCm: 35.0,
            bodyConditionScore: 3.0,
            aplomoNotes: 'Renguera interdigital',
            activeDiagnoses: [$pietin]
        );

        $this->assertSame(ReproductiveAptitudeStatus::IN_TREATMENT, $status);
    }

    public function test_sub_threshold_scrotal_circumference_yields_unfit_status(): void
    {
        $status = $this->engine->computeAptitude(
            scrotalCircumferenceCm: 26.0, // Carrillo threshold is >= 28.0cm
            bodyConditionScore: 3.5,
            aplomoNotes: 'Aplomos normales',
            activeDiagnoses: []
        );

        $this->assertSame(ReproductiveAptitudeStatus::UNFIT, $status);
    }

    public function test_extreme_low_body_condition_score_yields_unfit_status(): void
    {
        $status = $this->engine->computeAptitude(
            scrotalCircumferenceCm: 34.0,
            bodyConditionScore: 1.5, // Emaciated
            aplomoNotes: 'Aplomos normales',
            activeDiagnoses: []
        );

        $this->assertSame(ReproductiveAptitudeStatus::UNFIT, $status);
    }

    public function test_severe_aplomo_defect_notes_yields_unfit_status(): void
    {
        $status = $this->engine->computeAptitude(
            scrotalCircumferenceCm: 35.0,
            bodyConditionScore: 3.0,
            aplomoNotes: 'Artritis severa y deformación en tarso derecho con descarte locomotor',
            activeDiagnoses: []
        );

        $this->assertSame(ReproductiveAptitudeStatus::UNFIT, $status);
    }

    public function test_healthy_and_adequate_bull_yields_apt_status(): void
    {
        $status = $this->engine->computeAptitude(
            scrotalCircumferenceCm: 36.5,
            bodyConditionScore: 3.5,
            aplomoNotes: 'Aplomos correctos para servicio en campo natural',
            activeDiagnoses: []
        );

        $this->assertSame(ReproductiveAptitudeStatus::APT, $status);
    }
}
