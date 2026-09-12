<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Entities\VeterinaryDiagnosisEntity;
use App\Core\Enums\DiagnosisStatus;
use App\Core\Enums\LabSampleStatus;
use App\Core\Enums\ReproductiveAptitudeStatus;
use App\Core\Services\BullHealthEvaluationEngine;
use App\Core\ValueObjects\SampleResult;
use App\Core\ValueObjects\VenerealSamplingStatus;
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

    /**
     * ADR-4: correct biometry alone no longer certifies a bull. Without the required negative
     * venereal rounds on file the answer is PENDING_EVALUATION, not APT.
     */
    public function test_healthy_biometry_without_sampling_yields_pending_evaluation(): void
    {
        $status = $this->engine->computeAptitude(
            scrotalCircumferenceCm: 36.5,
            bodyConditionScore: 3.5,
            aplomoNotes: 'Aplomos correctos para servicio en campo natural',
            activeDiagnoses: [],
            sampling: VenerealSamplingStatus::empty()
        );

        $this->assertSame(ReproductiveAptitudeStatus::PENDING_EVALUATION, $status);
    }

    public function test_healthy_and_adequate_bull_with_two_negative_rounds_yields_apt_status(): void
    {
        $status = $this->engine->computeAptitude(
            scrotalCircumferenceCm: 36.5,
            bodyConditionScore: 3.5,
            aplomoNotes: 'Aplomos correctos para servicio en campo natural',
            activeDiagnoses: [],
            sampling: $this->samplingWithNegativeRounds([1, 2])
        );

        $this->assertSame(ReproductiveAptitudeStatus::APT, $status);
    }

    public function test_single_negative_round_is_not_enough_to_certify(): void
    {
        $status = $this->engine->computeAptitude(
            scrotalCircumferenceCm: 36.5,
            bodyConditionScore: 3.5,
            aplomoNotes: 'Aplomos correctos',
            activeDiagnoses: [],
            sampling: $this->samplingWithNegativeRounds([1])
        );

        $this->assertSame(ReproductiveAptitudeStatus::PENDING_EVALUATION, $status);
    }

    public function test_pending_laboratory_results_hold_the_bull_back(): void
    {
        $samples = $this->negativeSamples([1, 2]);
        $samples[] = new SampleResult(
            pathogenCode: 'TRITRICHOMONAS_FOETUS',
            round: 3,
            sampleDate: new DateTimeImmutable('-2 days'),
            status: LabSampleStatus::PENDING_RESULTS
        );

        $status = $this->engine->computeAptitude(
            scrotalCircumferenceCm: 36.5,
            bodyConditionScore: 3.5,
            aplomoNotes: 'Aplomos correctos',
            activeDiagnoses: [],
            sampling: new VenerealSamplingStatus($samples)
        );

        $this->assertSame(ReproductiveAptitudeStatus::PENDING_EVALUATION, $status);
    }

    public function test_standing_positive_sample_yields_unfit_even_with_perfect_biometry(): void
    {
        $samples = $this->negativeSamples([1, 2]);
        $samples[] = new SampleResult(
            pathogenCode: 'TRITRICHOMONAS_FOETUS',
            round: 3,
            sampleDate: new DateTimeImmutable('-5 days'),
            status: LabSampleStatus::POSITIVE_DETECTED
        );

        $status = $this->engine->computeAptitude(
            scrotalCircumferenceCm: 38.0,
            bodyConditionScore: 4.0,
            aplomoNotes: 'Aplomos correctos',
            activeDiagnoses: [],
            sampling: new VenerealSamplingStatus($samples)
        );

        $this->assertSame(ReproductiveAptitudeStatus::UNFIT, $status);
    }

    public function test_negative_rounds_outside_the_validity_window_do_not_certify(): void
    {
        $samples = [];

        foreach ([1, 2] as $round) {
            foreach (['TRITRICHOMONAS_FOETUS', 'CAMPYLOBACTER_FETUS'] as $code) {
                $samples[] = new SampleResult(
                    pathogenCode: $code,
                    round: $round,
                    // Beyond the 365 day validity window: the clearance has lapsed.
                    sampleDate: new DateTimeImmutable('-400 days'),
                    status: LabSampleStatus::NEGATIVE_CLEARED
                );
            }
        }

        $status = $this->engine->computeAptitude(
            scrotalCircumferenceCm: 36.5,
            bodyConditionScore: 3.5,
            aplomoNotes: 'Aplomos correctos',
            activeDiagnoses: [],
            sampling: new VenerealSamplingStatus($samples)
        );

        $this->assertSame(ReproductiveAptitudeStatus::PENDING_EVALUATION, $status);
    }

    /**
     * A later negative round supersedes an earlier positive: the bull is no longer a carrier.
     */
    public function test_negative_rounds_after_a_positive_restore_aptitude(): void
    {
        $samples = [];

        foreach (['TRITRICHOMONAS_FOETUS', 'CAMPYLOBACTER_FETUS'] as $code) {
            $samples[] = new SampleResult($code, 1, new DateTimeImmutable('-90 days'), LabSampleStatus::POSITIVE_DETECTED);
            $samples[] = new SampleResult($code, 2, new DateTimeImmutable('-40 days'), LabSampleStatus::NEGATIVE_CLEARED);
            $samples[] = new SampleResult($code, 3, new DateTimeImmutable('-20 days'), LabSampleStatus::NEGATIVE_CLEARED);
        }

        $status = $this->engine->computeAptitude(
            scrotalCircumferenceCm: 36.5,
            bodyConditionScore: 3.5,
            aplomoNotes: 'Aplomos correctos',
            activeDiagnoses: [],
            sampling: new VenerealSamplingStatus($samples)
        );

        $this->assertSame(ReproductiveAptitudeStatus::APT, $status);
    }

    /**
     * The two-stage rollout switch of ADR-4: with sampling enforcement off, the engine keeps
     * the legacy biometry-only behaviour so historical data can be loaded first.
     */
    public function test_disabling_enforcement_restores_biometry_only_aptitude(): void
    {
        $engine = new BullHealthEvaluationEngine(enforceSampling: false);

        $status = $engine->computeAptitude(
            scrotalCircumferenceCm: 36.5,
            bodyConditionScore: 3.5,
            aplomoNotes: 'Aplomos correctos',
            activeDiagnoses: [],
            sampling: VenerealSamplingStatus::empty()
        );

        $this->assertSame(ReproductiveAptitudeStatus::APT, $status);
    }

    /**
     * @param list<int> $rounds
     */
    private function samplingWithNegativeRounds(array $rounds): VenerealSamplingStatus
    {
        return new VenerealSamplingStatus($this->negativeSamples($rounds));
    }

    /**
     * @param list<int> $rounds
     * @return list<SampleResult>
     */
    private function negativeSamples(array $rounds): array
    {
        $samples = [];

        foreach ($rounds as $round) {
            foreach (['TRITRICHOMONAS_FOETUS', 'CAMPYLOBACTER_FETUS'] as $code) {
                $samples[] = new SampleResult(
                    pathogenCode: $code,
                    round: $round,
                    sampleDate: new DateTimeImmutable(sprintf('-%d days', 30 - ($round * 5))),
                    status: LabSampleStatus::NEGATIVE_CLEARED
                );
            }
        }

        return $samples;
    }
}
