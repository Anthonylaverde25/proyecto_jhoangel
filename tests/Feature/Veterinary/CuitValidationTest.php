<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\User;
use App\Models\Veterinarian;
use Illuminate\Support\Facades\DB;

/**
 * ADR-43: a mistyped CUIT is reported, never refused.
 *
 * The check digit is a typo detector — arithmetic inside the number itself, not a comparison
 * against anything stored. Two earlier versions got the balance wrong: the first threw from inside
 * the use case's transaction, aborting a whole chute session over one digit in an OPTIONAL field;
 * the second caught it at the request, which was better placed but still refused the work.
 *
 * Nothing in the system reads this number to decide anything. It exists so one institution's
 * history can be grouped, and a malformed number still groups consistently with itself. So it is
 * stored as typed and flagged as not checking out, and the interface warns beside the field.
 *
 * Nothing here compares the institution's CUIT against the professional's. No such comparison
 * exists anywhere: v9.1 removed it, because a differing CUIT means a different taxpayer, not a
 * derivation.
 */
class CuitValidationTest extends VeterinaryTestCase
{
    private Veterinarian $vet;
    private User $vetUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vet = $this->veterinarian('MP 4582');
        $this->vetUser = User::where('email', 'faranguren@ganadero.com')->firstOrFail();
    }

    public function test_a_mistyped_cuit_is_stored_and_flagged_not_refused(): void
    {
        // 11221122112: the digits a person invents. For 1122112211 the check digit is 0.
        $response = $this->emitAct(['nombre' => 'Centro de prueba', 'cuit' => '11221122112']);

        // The chute session closes. That is the point: it is the most expensive thing to redo.
        $response->assertStatus(201);
        $response->assertJsonPath('data.act_institution.cuit', '11221122112');
        // And the evidence carries the flag, so nobody reads it as a verified identifier.
        $response->assertJsonPath('data.act_institution.cuit_valido', false);
    }

    public function test_a_well_formed_cuit_is_flagged_as_such(): void
    {
        $response = $this->emitAct(['nombre' => 'Centro de prueba', 'cuit' => '11221122110']);

        $response->assertStatus(201);
        $response->assertJsonPath('data.act_institution.cuit_valido', true);
    }

    public function test_an_institution_without_a_cuit_closes_the_sheet(): void
    {
        // ADR-29: the CUIT groups, it does not certify. Not knowing it cannot block a chute.
        $this->emitAct(['nombre' => 'El laboratorio de la cooperativa'])
            ->assertStatus(201);
    }

    public function test_no_institution_at_all_closes_the_sheet(): void
    {
        $this->emitAct(null)->assertStatus(201);
    }

    public function test_an_institution_cuit_unrelated_to_the_professional_is_fine(): void
    {
        // The normal case for an employed veterinarian: the centre's CUIT is neither of theirs.
        $centre = ['nombre' => 'Laboratorio Regional Tandil', 'cuit' => '30712345671'];

        $this->assertNotContains(
            $centre['cuit'],
            array_filter([$this->vet->cuit, $this->vet->billing_cuit]),
            'El caso sólo prueba algo si el CUIT no es ninguno del profesional.'
        );

        $this->emitAct($centre)->assertStatus(201);
    }

    public function test_the_signature_accepts_a_mistyped_cuit_the_same_way(): void
    {
        // Consistent across screens on purpose: the same field behaving two ways depending on
        // where you are confuses more than it protects.
        $actId = (int) $this->emitAct(null)->json('data.id');

        $response = $this->apiAsUser($this->vetUser, 'POST', "/veterinary-portal/acts/{$actId}/sign", [
            'institution' => ['nombre' => 'Centro de prueba', 'cuit' => '11221122112'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.act_institution.cuit_valido', false);
    }

    public function test_text_that_holds_no_digits_is_the_same_as_no_cuit(): void
    {
        $response = $this->emitAct(['nombre' => 'Centro de prueba', 'cuit' => 'no lo sé']);

        $response->assertStatus(201);
        $response->assertJsonPath('data.act_institution.cuit', null);
    }

    /**
     * @param array<string, mixed>|null $institution
     * @return \Illuminate\Testing\TestResponse
     */
    private function emitAct(?array $institution)
    {
        $bulls = $this->bulls(2);

        $payload = [
            'veterinarian_id' => $this->vet->id,
            'evaluation_date' => now()->toDateString(),
            'sample_round' => 1,
            'bulls' => array_map(static fn ($bull): array => [
                'caravan_id' => $bull->id,
                'blood_serology' => true,
                'blood_serology_tube' => 'S-' . $bull->id . '-' . uniqid(),
            ], $bulls),
        ];

        if ($institution !== null) {
            $payload['institution'] = $institution;
        }

        return $this->apiAs('POST', '/pre-service/evaluation-sheets', $payload);
    }
}
