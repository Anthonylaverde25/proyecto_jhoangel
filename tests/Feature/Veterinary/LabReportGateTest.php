<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\User;
use App\Models\Veterinarian;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-35 (rev.): what has to be true before a laboratory result may be filed.
 *
 * The report no longer waits for a declared arrival — the samples may never have left the
 * professional's hands (ADR-30). Three things are required instead: a signed act, the institution
 * the report is filed from, and — when the professional declares the analysis was derived — the
 * other institution's own paper.
 */
class LabReportGateTest extends VeterinaryTestCase
{
    private Veterinarian $vet;
    private User $vetUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vet = $this->veterinarian('MP 4582');
        $this->vetUser = User::where('email', 'faranguren@ganadero.com')->firstOrFail();
    }

    public function test_report_is_rejected_when_act_is_unsigned(): void
    {
        $actId = $this->emitAct();
        $sampleId = $this->samplesOf($actId)[0];

        // The only gate left, and it is about the professional attesting their own work.
        $this->apiAsUser($this->vetUser, 'POST', "/veterinary-portal/acts/{$actId}/lab-report", [
            'lab_report_number' => 'LAB-GATE-1',
            'result_date' => now()->toDateString(),
            'reporting_institution' => ['nombre' => 'Laboratorio Regional Tandil'],
            'lines' => [['sample_id' => $sampleId, 'status' => 'NEGATIVE_CLEARED']],
        ])->assertStatus(422);
    }

    public function test_a_signed_act_needs_no_declared_arrival(): void
    {
        // ADR-30 / ADR-32: v7 demanded a registered reception here. The tubes may have been
        // processed in the professional's own laboratory and never travelled at all.
        $actId = $this->emitAct();
        $sampleId = $this->samplesOf($actId)[0];

        $this->apiAsUser($this->vetUser, 'POST', "/veterinary-portal/acts/{$actId}/sign", [])->assertStatus(200);

        $this->assertSame(
            0,
            DB::table('sample_shipments')->count(),
            'El caso in-house no genera ningún envío: los tubos nunca salieron de sus manos.'
        );

        $response = $this->apiAsUser($this->vetUser, 'POST', "/veterinary-portal/acts/{$actId}/lab-report", [
            'lab_report_number' => 'LAB-GATE-2',
            'result_date' => now()->toDateString(),
            'reporting_institution' => ['nombre' => 'Laboratorio Regional Tandil', 'cuit' => '30712345671'],
            'lines' => [['sample_id' => $sampleId, 'status' => 'NEGATIVE_CLEARED']],
        ]);

        $response->assertStatus(201);
        // He did not declare a derivation, so nothing is demanded and nothing is transcribed.
        $response->assertJsonPath('data.is_derived', false);
        $response->assertJsonPath('data.requires_analysis_attachment', false);
        $response->assertJsonPath('data.source_channel', 'PORTAL_VET');
    }

    public function test_requires_pdf_when_the_professional_declares_a_derivation(): void
    {
        Storage::fake('local');

        $actId = $this->emitAct();
        $sampleId = $this->samplesOf($actId)[0];
        $this->apiAsUser($this->vetUser, 'POST', "/veterinary-portal/acts/{$actId}/sign", [])->assertStatus(200);

        $ownCentre = ['nombre' => 'Laboratorio Regional Tandil', 'cuit' => '30712345671'];
        $referenceLab = ['nombre' => 'Laboratorio de Referencia Nacional', 'cuit' => '30709876542'];

        $this->apiAsUser($this->vetUser, 'POST', "/veterinary-portal/acts/{$actId}/lab-report", [
            'lab_report_number' => 'LAB-GATE-3',
            'result_date' => now()->toDateString(),
            'reporting_institution' => $ownCentre,
            'is_derived' => true,
            'analysing_institution' => $referenceLab,
            'lines' => [['sample_id' => $sampleId, 'status' => 'NEGATIVE_CLEARED']],
        ])->assertStatus(422);

        $withPdf = $this->actingAs($this->vetUser, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->post($this->url("/veterinary-portal/acts/{$actId}/lab-report"), [
                'lab_report_number' => 'LAB-GATE-3',
                'result_date' => now()->toDateString(),
                'reporting_institution' => json_encode($ownCentre),
                'is_derived' => '1',
                'analysing_institution' => json_encode($referenceLab),
                'lines' => json_encode([['sample_id' => $sampleId, 'status' => 'NEGATIVE_CLEARED']]),
                'attachments' => [UploadedFile::fake()->create('informe-referencia.pdf', 120, 'application/pdf')],
            ], ['Accept' => 'application/json']);

        $withPdf->assertStatus(201);
        $withPdf->assertJsonPath('data.is_derived', true);
        // ADR-9: a transcription does not weigh the same as a report its author filed.
        $withPdf->assertJsonPath('data.source_channel', 'OWNER_DIGITIZED');
        $withPdf->assertJsonPath('data.verification_status', 'UNVERIFIED');
    }

    public function test_no_pdf_is_demanded_for_work_signed_at_the_professionals_own_bench(): void
    {
        // The comparison this replaced asked Aranguren for a PDF of his own analysis whenever the
        // centre's CUIT was not literally one of his — which is the normal case for an employee.
        $actId = $this->emitAct();
        $sampleId = $this->samplesOf($actId)[0];
        $this->apiAsUser($this->vetUser, 'POST', "/veterinary-portal/acts/{$actId}/sign", [])->assertStatus(200);

        $response = $this->apiAsUser($this->vetUser, 'POST', "/veterinary-portal/acts/{$actId}/lab-report", [
            'lab_report_number' => 'LAB-GATE-4',
            'result_date' => now()->toDateString(),
            'reporting_institution' => ['nombre' => 'Laboratorio Regional Tandil', 'cuit' => '30712345671'],
            'lines' => [['sample_id' => $sampleId, 'status' => 'NEGATIVE_CLEARED']],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.is_derived', false);
        $response->assertJsonPath('data.requires_analysis_attachment', false);
    }

    public function test_a_report_must_name_the_institution_it_is_filed_from(): void
    {
        // ADR-29: the protocol carries institution and CUIT whether or not the tubes travelled.
        $actId = $this->emitAct();
        $sampleId = $this->samplesOf($actId)[0];
        $this->apiAsUser($this->vetUser, 'POST', "/veterinary-portal/acts/{$actId}/sign", [])->assertStatus(200);

        $this->apiAsUser($this->vetUser, 'POST', "/veterinary-portal/acts/{$actId}/lab-report", [
            'lab_report_number' => 'LAB-GATE-5',
            'result_date' => now()->toDateString(),
            'lines' => [['sample_id' => $sampleId, 'status' => 'NEGATIVE_CLEARED']],
        ])->assertStatus(422);
    }

    private function emitAct(): int
    {
        $bulls = $this->bulls(2);

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

    /**
     * @return list<int>
     */
    private function samplesOf(int $actId): array
    {
        return DB::table('bull_lab_samples')
            ->where('extraction_act_id', $actId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
