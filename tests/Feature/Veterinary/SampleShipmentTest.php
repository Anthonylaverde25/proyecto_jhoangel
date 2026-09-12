<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\User;
use App\Models\Veterinarian;
use Illuminate\Support\Facades\DB;

/**
 * ADR-30 / ADR-36: the professional declares what they dispatched, and the box belongs to the
 * establishment rather than to one chute session.
 */
class SampleShipmentTest extends VeterinaryTestCase
{
    private Veterinarian $vet;
    private User $vetUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vet = $this->veterinarian('MP 4582');
        $this->vetUser = User::where('email', 'faranguren@ganadero.com')->firstOrFail();
    }

    public function test_processing_in_house_needs_no_shipment(): void
    {
        // The tubes never left the professional's hands, so there is nothing to declare and the
        // system asks for nothing. v7 would have left this act waiting for an arrival forever.
        $actId = $this->signedAct(2);

        $this->assertSame(0, DB::table('sample_shipments')->count());
        $this->assertCount(2, $this->pendingTubeIds([$actId]));

        $act = $this->asVet('GET', "/veterinary-portal/acts/{$actId}");
        $act->assertJsonPath('data.shipped_samples_count', 0);
        $act->assertJsonPath('data.unshipped_samples_count', 2);
        // Still reportable: the analysis may well happen right here.
        $act->assertJsonPath('data.can_receive_lab_report', true);
    }

    public function test_a_fractioned_journey_is_two_shipments(): void
    {
        // The case the table exists for: one chute session, two trips on different days.
        $actId = $this->signedAct(4);
        $tubes = $this->pendingTubeIds([$actId]);

        $first = $this->asVet('POST', '/veterinary-portal/shipments', [
            'shipped_on' => now()->subDay()->toDateString(),
            'cold_chain_ok' => true,
            'institution' => ['nombre' => 'Laboratorio Rosario', 'cuit' => '30712345671'],
            'sample_ids' => array_slice($tubes, 0, 2),
        ]);

        $first->assertStatus(201);
        $first->assertJsonPath('data.samples_count', 2);

        $act = $this->asVet('GET', "/veterinary-portal/acts/{$actId}");
        $act->assertJsonPath('data.shipped_samples_count', 2);
        $act->assertJsonPath('data.unshipped_samples_count', 2);

        // The second trip finds only what is left: a tube travels once.
        $remaining = $this->pendingTubeIds([$actId]);
        $this->assertCount(2, $remaining);

        $this->asVet('POST', '/veterinary-portal/shipments', [
            'shipped_on' => now()->toDateString(),
            'cold_chain_ok' => true,
            'institution' => ['nombre' => 'Laboratorio Rosario', 'cuit' => '30712345671'],
            'sample_ids' => $remaining,
        ])->assertStatus(201);

        $this->assertSame(2, DB::table('sample_shipments')->count());
        $this->asVet('GET', "/veterinary-portal/acts/{$actId}")
            ->assertJsonPath('data.unshipped_samples_count', 0);
    }

    public function test_one_shipment_carries_tubes_from_several_acts(): void
    {
        // ADR-36: you pack a box, not an act. If the cooler arrives open that is one incident,
        // not two, which is exactly what hanging the row off an act made impossible to say.
        $firstAct = $this->signedAct(2);
        $secondAct = $this->signedAct(2);

        $response = $this->asVet('POST', '/veterinary-portal/shipments', [
            'shipped_on' => now()->toDateString(),
            'cold_chain_ok' => true,
            'institution' => ['nombre' => 'Laboratorio Rosario', 'cuit' => '30712345671'],
            'sample_ids' => $this->pendingTubeIds([$firstAct, $secondAct]),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.samples_count', 4);
        $response->assertJsonPath('data.acts_covered', 2);

        $this->assertNotSame($firstAct, $secondAct);
        $this->assertSame(1, DB::table('sample_shipments')->count());
    }

    public function test_a_tube_travels_only_once(): void
    {
        $actId = $this->signedAct(2);
        $tubes = $this->pendingTubeIds([$actId]);

        $this->asVet('POST', '/veterinary-portal/shipments', [
            'shipped_on' => now()->toDateString(),
            'cold_chain_ok' => true,
            'institution' => ['nombre' => 'Laboratorio Rosario', 'cuit' => '30712345671'],
            'sample_ids' => $tubes,
        ])->assertStatus(201);

        $this->asVet('POST', '/veterinary-portal/shipments', [
            'shipped_on' => now()->toDateString(),
            'cold_chain_ok' => true,
            'institution' => ['nombre' => 'Otro Laboratorio', 'cuit' => '30709876542'],
            'sample_ids' => $tubes,
        ])->assertStatus(422);
    }

    public function test_broken_cold_chain_requires_a_note(): void
    {
        // §4: the record is never blocked — the professional judges whether the sample is still
        // usable — but the judgement has to be written down or it cannot be audited.
        $actId = $this->signedAct(1);

        $this->asVet('POST', '/veterinary-portal/shipments', [
            'shipped_on' => now()->toDateString(),
            'cold_chain_ok' => false,
            'institution' => ['nombre' => 'Laboratorio Rosario'],
            'sample_ids' => $this->pendingTubeIds([$actId]),
        ])->assertStatus(422);

        $this->asVet('POST', '/veterinary-portal/shipments', [
            'shipped_on' => now()->toDateString(),
            'cold_chain_ok' => false,
            'condition_notes' => 'Conservadora sin hielo desde las 14 h; muestras aún frías al tacto.',
            'institution' => ['nombre' => 'Laboratorio Rosario'],
            'sample_ids' => $this->pendingTubeIds([$actId]),
        ])->assertStatus(201);
    }

    public function test_a_bad_cuit_is_stored_and_flagged_not_refused(): void
    {
        // ADR-43: the CUIT describes, it does not gate. A dispatch is a physical fact that already
        // happened — the tubes are in somebody's hands — so a mistyped digit cannot be what stops
        // it from being recorded. The response says the number does not check out and stores it.
        $actId = $this->signedAct(1);

        $response = $this->asVet('POST', '/veterinary-portal/shipments', [
            'shipped_on' => now()->toDateString(),
            'cold_chain_ok' => true,
            'institution' => ['nombre' => 'Laboratorio Rosario', 'cuit' => '30712345679'],
            'sample_ids' => $this->pendingTubeIds([$actId]),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.institution.cuit', '30712345679');
        $response->assertJsonPath('data.institution.cuit_valido', false);
    }

    public function test_one_scrape_is_one_physical_tube_with_two_determinations(): void
    {
        /*
         * `pending-tubes` returns one entry per DETERMINATION, not per tube, and that is correct:
         * a preputial scrape is cultured for both venereal agents, and the aptitude engine counts
         * negative rounds per agent, so "negative to trichomonas, positive to campylobacter" has
         * to be representable.
         *
         * What it means for whoever consumes the endpoint is that two entries can share one tube
         * number, and they are one piece of glass. Half a tube cannot be dispatched, so the two
         * have to travel together — this test pins the shape the UI has to group on.
         */
        $actId = $this->signedActWithScrape();

        $pending = $this->asVet('GET', '/veterinary-portal/pending-tubes')->json('data');

        $ofThisAct = array_values(array_filter(
            $pending,
            static fn (array $row): bool => (int) $row['extraction_act_id'] === $actId
        ));

        $this->assertCount(2, $ofThisAct, 'Un raspaje rinde dos determinaciones.');

        $tubeNumbers = array_unique(array_column($ofThisAct, 'tube_number'));
        $this->assertCount(1, $tubeNumbers, 'Y las dos son el mismo tubo físico.');
        $this->assertCount(2, array_unique(array_column($ofThisAct, 'id')), 'Con ids distintos.');
    }

    public function test_a_tube_travels_whole_with_every_determination_on_it(): void
    {
        $actId = $this->signedActWithScrape();

        $pending = $this->asVet('GET', '/veterinary-portal/pending-tubes')->json('data');
        $ids = array_values(array_map(
            static fn (array $row): int => (int) $row['id'],
            array_filter($pending, static fn (array $row): bool => (int) $row['extraction_act_id'] === $actId)
        ));

        $this->asVet('POST', '/veterinary-portal/shipments', [
            'shipped_on' => now()->toDateString(),
            'cold_chain_ok' => true,
            'institution' => ['nombre' => 'Laboratorio Rosario', 'cuit' => '30712345671'],
            'sample_ids' => $ids,
        ])->assertStatus(201);

        // Nothing of that tube stays behind: the glass is either in the box or it is not.
        $stillPending = $this->asVet('GET', '/veterinary-portal/pending-tubes')->json('data');
        $leftOver = array_filter(
            $stillPending,
            static fn (array $row): bool => (int) $row['extraction_act_id'] === $actId
        );

        $this->assertEmpty($leftOver, 'Un tubo viaja entero: no quedan determinaciones sueltas.');
    }

    public function test_unsigned_acts_offer_no_tubes_to_dispatch(): void
    {
        // An unsigned act has no legally identified tubes to hand over.
        $actId = $this->emitAct(2);

        $this->assertCount(0, $this->pendingTubeIds([$actId]));
    }

    // ------------------------------------------------------------------ helpers

    /** An act with a single preputial scrape, which the config expands into two determinations. */
    private function signedActWithScrape(): int
    {
        $bull = $this->bulls(1)[0];

        $response = $this->apiAs('POST', '/pre-service/evaluation-sheets', [
            'veterinarian_id' => $this->vet->id,
            'evaluation_date' => now()->toDateString(),
            'sample_round' => 1,
            'bulls' => [[
                'caravan_id' => $bull->id,
                'prepuce_scrape' => true,
                'prepuce_scrape_tube' => 'R-' . $bull->id . '-' . uniqid(),
            ]],
        ]);

        $response->assertStatus(201);
        $actId = (int) $response->json('data.id');

        $this->asVet('POST', "/veterinary-portal/acts/{$actId}/sign", [])->assertStatus(200);

        return $actId;
    }

    private function emitAct(int $bullCount): int
    {
        $bulls = $this->bulls($bullCount);

        $response = $this->apiAs('POST', '/pre-service/evaluation-sheets', [
            'veterinarian_id' => $this->vet->id,
            'evaluation_date' => now()->toDateString(),
            'sample_round' => 1,
            'bulls' => array_map(static fn ($bull): array => [
                'caravan_id' => $bull->id,
                'scrotal_circumference_cm' => 34,
                'blood_serology' => true,
                'blood_serology_tube' => 'S-' . $bull->id . '-' . uniqid(),
            ], $bulls),
        ]);

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    private function signedAct(int $bullCount): int
    {
        $actId = $this->emitAct($bullCount);
        $this->asVet('POST', "/veterinary-portal/acts/{$actId}/sign", [])->assertStatus(200);

        return $actId;
    }

    /**
     * The tenant seeder leaves acts of this professional already loaded, so every assertion is
     * scoped to the acts the test itself created.
     *
     * @param list<int> $actIds
     * @return list<int>
     */
    private function pendingTubeIds(array $actIds): array
    {
        $tubes = $this->asVet('GET', '/veterinary-portal/pending-tubes')->json('data');

        return array_values(array_map(
            static fn (array $tube): int => (int) $tube['id'],
            array_filter(
                $tubes,
                static fn (array $tube): bool => in_array((int) $tube['extraction_act_id'], $actIds, true)
            )
        ));
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function asVet(string $method, string $path, array $payload = [])
    {
        return $this->apiAsUser($this->vetUser, $method, $path, $payload);
    }
}
