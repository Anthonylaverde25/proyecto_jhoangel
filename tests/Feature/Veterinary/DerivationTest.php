<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\User;
use App\Models\Veterinarian;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-31 (rev.): the professional declares the derivation. Nothing on file deduces it.
 *
 * The case that produced this design: the professional keeps the samples in their own laboratory
 * for a day and then sends them on to an officially designated one.
 *
 * The first version read "the analysing CUIT differs from the professional's" as "it was
 * derived". That is not a derivation, it is a different taxpayer — an employed veterinarian's
 * CUIT differs from their employer's laboratory and the samples never left the building. The
 * comparison demanded somebody else's PDF for work signed at their own bench, so the fact is now
 * stated by the only person who holds it, and the report carries two institutions: the centre it
 * is filed from, always, and the third party that ran the assay, only on a derivation.
 */
class DerivationTest extends VeterinaryTestCase
{
    private Veterinarian $vet;
    private User $vetUser;

    /** The centre Aranguren reports from, named on every report he files. */
    private const OWN_CENTRE = [
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

    public function test_a_declared_derivation_records_both_institutions(): void
    {
        Storage::fake('local');

        [$actId, $tubes] = $this->signedActWithTubes();

        // The tubes were stored for a day and then handed on to the designated laboratory.
        $this->asVet('POST', '/veterinary-portal/shipments', [
            'shipped_on' => now()->toDateString(),
            'cold_chain_ok' => true,
            'condition_notes' => 'Conservadas a 4 °C en el laboratorio propio desde el día anterior.',
            'institution' => ['nombre' => 'Laboratorio de Referencia Nacional', 'cuit' => '30709876542'],
            'sample_ids' => $tubes,
        ])->assertStatus(201);

        $report = $this->actingAs($this->vetUser, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->post($this->url("/veterinary-portal/acts/{$actId}/lab-report"), [
                'lab_report_number' => 'LAB-DERIV-1',
                'result_date' => now()->toDateString(),
                'reporting_institution' => json_encode(self::OWN_CENTRE),
                'is_derived' => '1',
                'analysing_institution' => json_encode([
                    'nombre' => 'Laboratorio de Referencia Nacional',
                    'cuit' => '30709876542',
                ]),
                'lines' => json_encode([['sample_id' => $tubes[0], 'status' => 'NEGATIVE_CLEARED']]),
                'attachments' => [UploadedFile::fake()->create('referencia.pdf', 90, 'application/pdf')],
            ], ['Accept' => 'application/json']);

        $report->assertStatus(201);
        $report->assertJsonPath('data.is_derived', true);
        // Both halves of the story: from whose bench it was sent, and who ran it.
        $report->assertJsonPath('data.reporting_institution.cuit', self::OWN_CENTRE['cuit']);
        $report->assertJsonPath('data.analysing_institution.cuit', '30709876542');
        // ADR-9: a transcription of somebody else's analysis carries less weight.
        $report->assertJsonPath('data.source_channel', 'OWNER_DIGITIZED');
    }

    public function test_processing_in_house_still_names_the_institution(): void
    {
        [$actId, $tubes] = $this->signedActWithTubes();

        $report = $this->asVet('POST', "/veterinary-portal/acts/{$actId}/lab-report", [
            'lab_report_number' => 'LAB-DERIV-2',
            'result_date' => now()->toDateString(),
            'reporting_institution' => self::OWN_CENTRE,
            'lines' => [['sample_id' => $tubes[0], 'status' => 'NEGATIVE_CLEARED']],
        ]);

        $report->assertStatus(201);
        // The protocol carries institution and CUIT even though the work never travelled.
        $report->assertJsonPath('data.reporting_institution.nombre', self::OWN_CENTRE['nombre']);
        $report->assertJsonPath('data.reporting_institution.cuit', self::OWN_CENTRE['cuit']);
        $report->assertJsonPath('data.is_derived', false);
        // No second institution is invented for a report that never left the premises.
        $report->assertJsonPath('data.analysing_institution', null);
        $report->assertJsonPath('data.source_channel', 'PORTAL_VET');
    }

    public function test_an_employers_cuit_is_not_a_derivation(): void
    {
        // The regression this redesign exists for. Tandil's CUIT is neither of the CUITs on
        // Aranguren's file, and the old comparison therefore called it somebody else's analysis
        // and refused the report until he attached a PDF of his own work.
        [$actId, $tubes] = $this->signedActWithTubes();

        $this->assertNotContains(
            self::OWN_CENTRE['cuit'],
            array_filter([$this->vet->cuit, $this->vet->billing_cuit]),
            'El caso sólo prueba algo si el CUIT de la institución no es ninguno del profesional.'
        );

        $report = $this->asVet('POST', "/veterinary-portal/acts/{$actId}/lab-report", [
            'lab_report_number' => 'LAB-DERIV-3',
            'result_date' => now()->toDateString(),
            'reporting_institution' => self::OWN_CENTRE,
            'lines' => [['sample_id' => $tubes[0], 'status' => 'NEGATIVE_CLEARED']],
        ]);

        $report->assertStatus(201);
        $report->assertJsonPath('data.is_derived', false);
        $report->assertJsonPath('data.requires_analysis_attachment', false);
    }

    public function test_declaring_a_derivation_without_naming_the_processor_is_rejected(): void
    {
        [$actId, $tubes] = $this->signedActWithTubes();

        $this->asVet('POST', "/veterinary-portal/acts/{$actId}/lab-report", [
            'lab_report_number' => 'LAB-DERIV-4',
            'result_date' => now()->toDateString(),
            'reporting_institution' => self::OWN_CENTRE,
            'is_derived' => true,
            'lines' => [['sample_id' => $tubes[0], 'status' => 'NEGATIVE_CLEARED']],
        ])->assertStatus(422);
    }

    public function test_the_report_always_names_the_institution_it_comes_from(): void
    {
        [$actId, $tubes] = $this->signedActWithTubes();

        $this->asVet('POST', "/veterinary-portal/acts/{$actId}/lab-report", [
            'lab_report_number' => 'LAB-DERIV-5',
            'result_date' => now()->toDateString(),
            'lines' => [['sample_id' => $tubes[0], 'status' => 'NEGATIVE_CLEARED']],
        ])->assertStatus(422);
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
