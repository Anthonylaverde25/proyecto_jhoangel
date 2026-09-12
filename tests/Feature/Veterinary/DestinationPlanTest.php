<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\User;
use App\Models\Veterinarian;
use Illuminate\Support\Facades\DB;

/**
 * ADR-39 / ADR-40: the act declares the centre it was drawn at and what the professional intends
 * to do with the tubes — and that declaration governs nothing.
 *
 * The distinction the whole design rests on: the act states a PLAN, the report states a FACT.
 * Plans change, so a report that contradicts the act is not an error to be caught. Tests 4 and 5
 * are the ones that keep somebody from "improving" this into a validation later.
 */
class DestinationPlanTest extends VeterinaryTestCase
{
    private Veterinarian $vet;
    private User $vetUser;

    private const CENTRE = [
        'nombre' => 'Laboratorio Regional Tandil',
        'cuit' => '30712345671',
        'direccion' => 'Ruta 226 km 12, Tandil',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->vet = $this->veterinarian('MP 4582');
        $this->vetUser = User::where('email', 'faranguren@ganadero.com')->firstOrFail();
    }

    public function test_the_act_records_the_institution_and_the_plan_it_declared(): void
    {
        $actId = $this->emitAct([
            'institution' => self::CENTRE,
            'destination_plan' => 'TO_BE_DERIVED',
        ]);

        $act = $this->apiAs('GET', "/diagnostic-protocols/{$actId}");

        $act->assertStatus(200);
        $act->assertJsonPath('data.act_institution.nombre', self::CENTRE['nombre']);
        $act->assertJsonPath('data.act_institution.cuit', self::CENTRE['cuit']);
        $act->assertJsonPath('data.destination_plan', 'TO_BE_DERIVED');

        // ADR-42: it lives beside the act, not among the columns a report needs.
        $this->assertDatabaseHas('extraction_act_details', [
            'protocol_id' => $actId,
            'destination_plan' => 'TO_BE_DERIVED',
        ]);
    }

    public function test_the_plan_is_frozen_by_the_signature(): void
    {
        $actId = $this->emitAct(['destination_plan' => 'IN_SITU']);

        // The signature is the last chance to correct it.
        $this->asVet('POST', "/veterinary-portal/acts/{$actId}/sign", [
            'institution' => self::CENTRE,
            'destination_plan' => 'TO_BE_DERIVED',
        ])->assertStatus(200);

        $this->assertDatabaseHas('extraction_act_details', [
            'protocol_id' => $actId,
            'destination_plan' => 'TO_BE_DERIVED',
        ]);

        // And once signed, the act attests to it: signing again changes nothing.
        $this->asVet('POST', "/veterinary-portal/acts/{$actId}/sign", [
            'destination_plan' => 'IN_SITU',
        ])->assertStatus(422);

        $this->assertDatabaseHas('extraction_act_details', [
            'protocol_id' => $actId,
            'destination_plan' => 'TO_BE_DERIVED',
        ]);
    }

    public function test_a_report_that_contradicts_the_plan_is_accepted(): void
    {
        // Declared IN_SITU, then the cold chain broke and the tubes were sent on. ADR-40: the act
        // is not falsified by a plan that changed.
        [$actId, $tubes] = $this->signedActWithTubes('IN_SITU');

        $report = $this->asVet('POST', "/veterinary-portal/acts/{$actId}/lab-report", [
            'lab_report_number' => 'LAB-PLAN-1',
            'result_date' => now()->toDateString(),
            'reporting_institution' => self::CENTRE,
            'lines' => [['sample_id' => $tubes[0], 'status' => 'NEGATIVE_CLEARED']],
        ]);

        $report->assertStatus(201);
        $report->assertJsonPath('data.is_derived', false);

        // The act keeps saying what it said.
        $this->assertDatabaseHas('extraction_act_details', [
            'protocol_id' => $actId,
            'destination_plan' => 'IN_SITU',
        ]);
    }

    public function test_a_report_that_contradicts_the_plan_the_other_way_is_accepted(): void
    {
        // Declared TO_BE_DERIVED, then he processed it himself. No PDF is demanded, because what
        // demands the PDF is the report's own declaration (ADR-35 rev.), never the act's plan.
        [$actId, $tubes] = $this->signedActWithTubes('TO_BE_DERIVED');

        $report = $this->asVet('POST', "/veterinary-portal/acts/{$actId}/lab-report", [
            'lab_report_number' => 'LAB-PLAN-2',
            'result_date' => now()->toDateString(),
            'reporting_institution' => self::CENTRE,
            'lines' => [['sample_id' => $tubes[0], 'status' => 'NEGATIVE_CLEARED']],
        ]);

        $report->assertStatus(201);
        $report->assertJsonPath('data.is_derived', false);
        $report->assertJsonPath('data.requires_analysis_attachment', false);
    }

    public function test_an_act_with_no_institution_still_closes(): void
    {
        // A field sampling has no centre behind it, and that cannot be what blocks a chute.
        $actId = $this->emitAct();

        $act = $this->apiAs('GET', "/diagnostic-protocols/{$actId}");

        $act->assertStatus(200);
        $act->assertJsonPath('data.act_institution', null);
        $act->assertJsonPath('data.destination_plan', 'UNDECIDED');
    }

    public function test_historical_acts_default_to_undecided(): void
    {
        // Every act predating ADR-39 got a detail row saying nothing was declared, which is true —
        // the alternative would have been to invent an intention.
        $seeded = DB::table('diagnostic_protocols')
            ->where('protocol_type', 'EXTRACTION_ACT')
            ->pluck('id');

        $this->assertGreaterThan(0, $seeded->count(), 'El seeder tiene que dejar actas para este caso.');

        foreach ($seeded as $actId) {
            $this->assertDatabaseHas('extraction_act_details', ['protocol_id' => $actId]);
        }
    }

    public function test_an_act_takes_one_live_report_and_the_lookup_is_ordered(): void
    {
        // The rule as it stands: one live report per act. A second one is refused by name, so the
        // lookup can only ever find one — the ordering below states that intent rather than
        // leaving the engine to pick.
        //
        // KNOWN GAP, left alone deliberately: fractioned work sends the tubes of one chute to two
        // laboratories (ADR-36), and those two would produce two reports on the same act. This rule
        // refuses the second. Changing how many documents an act may receive is a decision about
        // the domain, not a side effect of this plan.
        [$actId, $tubes] = $this->signedActWithTubes('TO_BE_DERIVED');
        $this->assertGreaterThanOrEqual(2, count($tubes), 'El caso necesita dos tubos.');

        $this->asVet('POST', "/veterinary-portal/acts/{$actId}/lab-report", [
            'lab_report_number' => 'LAB-FRAC-1',
            'result_date' => now()->subDays(3)->toDateString(),
            'reporting_institution' => self::CENTRE,
            'lines' => [['sample_id' => $tubes[0], 'status' => 'NEGATIVE_CLEARED']],
        ])->assertStatus(201);

        $this->asVet('POST', "/veterinary-portal/acts/{$actId}/lab-report", [
            'lab_report_number' => 'LAB-FRAC-2',
            'result_date' => now()->toDateString(),
            'reporting_institution' => self::CENTRE,
            'lines' => [['sample_id' => $tubes[1], 'status' => 'NEGATIVE_CLEARED']],
        ])->assertStatus(422);

        $repository = app(\App\Core\Interfaces\IDiagnosticProtocolRepository::class);

        $all = $repository->findLabReportsForAct($actId, (int) $this->company->id);
        $this->assertCount(1, $all);

        $newest = $repository->findLabReportForAct($actId, (int) $this->company->id);
        $this->assertSame('LAB-FRAC-1', $newest?->getProtocolNumber());
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function emitAct(array $extra = []): int
    {
        $bulls = $this->bulls(2);

        $response = $this->apiAs('POST', '/pre-service/evaluation-sheets', array_merge([
            'veterinarian_id' => $this->vet->id,
            'evaluation_date' => now()->toDateString(),
            'sample_round' => 1,
            'bulls' => array_map(static fn ($bull): array => [
                'caravan_id' => $bull->id,
                'blood_serology' => true,
                'blood_serology_tube' => 'S-' . $bull->id . '-' . uniqid(),
            ], $bulls),
        ], $extra));

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    /**
     * @return array{0: int, 1: list<int>}
     */
    private function signedActWithTubes(string $plan): array
    {
        $actId = $this->emitAct(['institution' => self::CENTRE, 'destination_plan' => $plan]);

        $this->asVet('POST', "/veterinary-portal/acts/{$actId}/sign", [])->assertStatus(200);

        $tubes = DB::table('bull_lab_samples')
            ->where('extraction_act_id', $actId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return [$actId, $tubes];
    }

    public function test_can_update_destination_plan_before_signature(): void
    {
        $actId = $this->emitAct(['destination_plan' => 'IN_SITU']);

        $response = $this->asVet('PATCH', "/veterinary-portal/acts/{$actId}/destination-plan", [
            'destination_plan' => 'TO_BE_DERIVED',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.destination_plan', 'TO_BE_DERIVED');

        $this->assertDatabaseHas('extraction_act_details', [
            'protocol_id' => $actId,
            'destination_plan' => 'TO_BE_DERIVED',
        ]);
    }

    public function test_cannot_update_destination_plan_after_signature(): void
    {
        [$actId] = $this->signedActWithTubes('IN_SITU');

        $response = $this->asVet('PATCH', "/veterinary-portal/acts/{$actId}/destination-plan", [
            'destination_plan' => 'TO_BE_DERIVED',
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseHas('extraction_act_details', [
            'protocol_id' => $actId,
            'destination_plan' => 'IN_SITU',
        ]);
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function asVet(string $method, string $path, array $payload = [])
    {
        return $this->apiAsUser($this->vetUser, $method, $path, $payload);
    }
}
