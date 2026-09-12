<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\User;
use App\Models\Veterinarian;
use Illuminate\Support\Facades\DB;

/**
 * ADR-37: a typo the same afternoon does not deserve ceremony; a correction to evidence somebody
 * already cited does.
 */
class ShipmentCorrectionTest extends VeterinaryTestCase
{
    private Veterinarian $vet;
    private User $vetUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vet = $this->veterinarian('MP 4582');
        $this->vetUser = User::where('email', 'faranguren@ganadero.com')->firstOrFail();
    }

    public function test_is_editable_until_a_report_cites_its_tubes(): void
    {
        [$actId, $tubes] = $this->signedActWithTubes();

        $shipment = $this->asVet('POST', '/veterinary-portal/shipments', [
            'shipped_on' => now()->toDateString(),
            'cold_chain_ok' => true,
            'institution' => ['nombre' => 'Laboratorio Rosario', 'cuit' => '30712345671'],
            'sample_ids' => $tubes,
        ]);

        $shipment->assertStatus(201);
        $shipment->assertJsonPath('data.is_editable', true);
        $shipment->assertJsonPath('data.is_cited_by_report', false);

        $shipmentId = (int) $shipment->json('data.id');

        // Wrong CUIT, caught the same day: corrected in place.
        $corrected = $this->asVet('PATCH', "/veterinary-portal/shipments/{$shipmentId}", [
            'shipped_on' => now()->toDateString(),
            'cold_chain_ok' => true,
            'institution' => ['nombre' => 'Laboratorio Rural del Sur', 'cuit' => '30709876542'],
            'sample_ids' => $tubes,
        ]);

        $corrected->assertStatus(200);
        $corrected->assertJsonPath('data.institution.nombre', 'Laboratorio Rural del Sur');
        $corrected->assertJsonPath('data.institution.cuit', '30709876542');
    }

    public function test_after_a_report_it_is_voided_with_a_reason(): void
    {
        [$actId, $tubes] = $this->signedActWithTubes();

        $shipmentId = (int) $this->asVet('POST', '/veterinary-portal/shipments', [
            'shipped_on' => now()->toDateString(),
            'cold_chain_ok' => true,
            'institution' => ['nombre' => 'Laboratorio Rosario', 'cuit' => '30712345671'],
            'sample_ids' => $tubes,
        ])->json('data.id');

        $this->asVet('POST', "/veterinary-portal/acts/{$actId}/lab-report", [
            'lab_report_number' => 'LAB-CORR-1',
            'result_date' => now()->toDateString(),
            'reporting_institution' => ['nombre' => 'Laboratorio Regional Tandil', 'cuit' => '30712345671'],
            'lines' => [['sample_id' => $tubes[0], 'status' => 'NEGATIVE_CLEARED']],
        ])->assertStatus(201);

        // Somebody relied on this document: correcting it in place would change the meaning of
        // evidence that was already cited.
        $this->asVet('PATCH', "/veterinary-portal/shipments/{$shipmentId}", [
            'shipped_on' => now()->toDateString(),
            'cold_chain_ok' => true,
            'institution' => ['nombre' => 'Otro Laboratorio', 'cuit' => '30709876542'],
            'sample_ids' => $tubes,
        ])->assertStatus(422);

        $voided = $this->asVet('POST', "/veterinary-portal/shipments/{$shipmentId}/void", [
            'reason' => 'Se cargó el laboratorio equivocado; la conservadora fue a Rural del Sur.',
        ]);

        $voided->assertStatus(200);
        $voided->assertJsonPath('data.is_voided', true);
        $voided->assertJsonPath('data.void_reason', 'Se cargó el laboratorio equivocado; la conservadora fue a Rural del Sur.');

        // Nothing is erased, and the tube the laboratory already resolved keeps its shipment:
        // voiding a box never rewrites a result.
        $this->assertSame(
            $shipmentId,
            (int) DB::table('bull_lab_samples')->where('id', $tubes[0])->value('sample_shipment_id')
        );

        // The unreported tube goes back to the professional so a corrected shipment can be made.
        $this->assertNull(
            DB::table('bull_lab_samples')->where('id', $tubes[1])->value('sample_shipment_id')
        );
    }

    public function test_voiding_demands_a_reason(): void
    {
        [, $tubes] = $this->signedActWithTubes();

        $shipmentId = (int) $this->asVet('POST', '/veterinary-portal/shipments', [
            'shipped_on' => now()->toDateString(),
            'cold_chain_ok' => true,
            'institution' => ['nombre' => 'Laboratorio Rosario', 'cuit' => '30712345671'],
            'sample_ids' => $tubes,
        ])->json('data.id');

        $this->asVet('POST', "/veterinary-portal/shipments/{$shipmentId}/void", ['reason' => ''])
            ->assertStatus(422);
    }

    /**
     * @return array{0: int, 1: list<int>}
     */
    private function signedActWithTubes(): array
    {
        $bulls = $this->bulls(2);

        $response = $this->apiAs('POST', '/pre-service/evaluation-sheets', [
            'veterinarian_id' => $this->vet->id,
            'evaluation_date' => now()->toDateString(),
            'sample_round' => 1,
            'bulls' => array_map(static fn ($bull): array => [
                'caravan_id' => $bull->id,
                'blood_serology' => true,
                'blood_serology_tube' => 'S-' . $bull->id . '-' . uniqid(),
            ], $bulls),
        ]);

        $response->assertStatus(201);
        $actId = (int) $response->json('data.id');
        $this->asVet('POST', "/veterinary-portal/acts/{$actId}/sign", [])->assertStatus(200);

        $tubes = DB::table('bull_lab_samples')
            ->where('extraction_act_id', $actId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return [$actId, $tubes];
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function asVet(string $method, string $path, array $payload = [])
    {
        return $this->apiAsUser($this->vetUser, $method, $path, $payload);
    }
}
