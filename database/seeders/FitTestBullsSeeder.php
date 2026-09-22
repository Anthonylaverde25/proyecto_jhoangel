<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\Services\RecomputeBullAptitudeBulkService;
use App\Core\Enums\DiagnosticProtocolType;
use App\Core\Enums\DiagnosticSourceChannel;
use App\Core\Enums\LabSampleStatus;
use App\Core\Enums\ProtocolStatus;
use App\Core\Enums\ProtocolVerificationStatus;
use App\Core\Enums\ReproductiveAptitudeStatus;
use App\Core\Enums\SampleDestinationPlan;
use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\BullHealthEvaluation;
use App\Models\BullLabSample;
use App\Models\Caravan;
use App\Models\Company;
use App\Models\DiagnosticProtocol;
use App\Models\ExtractionActDetail;
use App\Models\Pathogen;
use App\Models\Veterinarian;
use App\Models\VeterinarianBatchAssignment;
use App\Models\VeterinaryDiagnosis;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Counterpart of UnevaluatedTestBullsSeeder: three bulls whose every reproductive parameter is
 * correct, so the APT path can be exercised with a known, clean history instead of borrowing one
 * of the TR-0xx demo bulls.
 *
 * What makes each of them APT under BullHealthEvaluationEngine (ADR-4):
 *
 *  - A recent chute examination: scrotal circumference and body condition above the minimums,
 *    aplomo notes without disqualifying terms.
 *  - Two negative rounds for every required venereal pathogen, inside the validity window, each
 *    drawn under a SIGNED extraction act (unsigned evidence clears nobody) and resolved by a
 *    signed lab report. Processed in situ, so no shipment is involved.
 *  - No active diagnoses.
 *
 * Like the raw trio it runs after BullAptitudeRecalculationSeeder, purges any previous history
 * for these caravans, and recomputes aptitude itself with the real engine so the stored status
 * is the one the rules produce, not a hardcoded one.
 */
class FitTestBullsSeeder extends Seeder
{
    private const BATCH_NAME = 'Lote Testing Sanitario (aptos)';

    /**
     * tag => chute examination.
     */
    private const BULLS = [
        'ANTHONY-TORO-TEST-004' => [
            'ce' => 36.5,
            'cc' => 3.5,
            'libido' => 'ALTA',
            'aplomo' => 'Aplomos correctos, pezuñas simétricas. Testículos elásticos, móviles y simétricos.',
        ],
        'ANTHONY-TORO-TEST-005' => [
            'ce' => 38.0,
            'cc' => 3.5,
            'libido' => 'MUY_ALTA',
            'aplomo' => 'Excelente paralelismo de miembros posteriores y ángulo de garrón adecuado. Prepucio corto y limpio.',
        ],
        'ANTHONY-TORO-TEST-006' => [
            'ce' => 35.0,
            'cc' => 3.0,
            'libido' => 'ALTA',
            'aplomo' => 'Aplomos normales, desplazamiento amplio y coordinado. Sin lesiones podales.',
        ],
    ];

    /**
     * Round => [act number, report number, days ago the tubes were drawn].
     */
    private const ROUNDS = [
        1 => ['ACTA-SEED-APTOS-R1', 'LAB-SEED-APTOS-R1', 30],
        2 => ['ACTA-SEED-APTOS-R2', 'LAB-SEED-APTOS-R2', 15],
    ];

    public function run(RecomputeBullAptitudeBulkService $recompute): void
    {
        $company = Company::first();

        if (!$company) {
            $this->command?->warn('FitTestBullsSeeder: no hay compañía cargada. Se omite.');

            return;
        }

        $companyId = (int) $company->id;

        $veterinarian = Veterinarian::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->first();

        if ($veterinarian === null) {
            $this->command?->warn('FitTestBullsSeeder: no hay veterinario con cuenta para firmar las actas. Se omite.');

            return;
        }

        $pathogenIds = Pathogen::withoutGlobalScopes()
            ->whereIn('code', (array) config('livestock.venereal.required_pathogen_codes', []))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($pathogenIds === []) {
            $this->command?->warn('FitTestBullsSeeder: faltan patógenos venéreos. Se omite.');

            return;
        }

        $batch = $this->resolveOwnBreedingBatch($companyId);
        $bulls = $this->resolveBulls($companyId, (int) $batch->id);
        $caravanIds = array_values($bulls);

        $this->stripSanitaryHistory($companyId, $caravanIds);
        $this->recordChuteExaminations($companyId, $bulls);

        foreach (self::ROUNDS as $round => [$actNumber, $reportNumber, $daysAgo]) {
            $act = $this->createSignedAct($companyId, $veterinarian, $actNumber, $daysAgo);
            $report = $this->createSignedReport($companyId, $veterinarian, $act, $reportNumber, $daysAgo);
            $this->createNegativeTubes($companyId, $veterinarian, $act, $report, $caravanIds, $pathogenIds, $round);
        }

        $this->assignToPortalVeterinarian($companyId, $veterinarian, (int) $batch->id);

        $statuses = $recompute->__invoke($caravanIds, $companyId);
        $notApt = array_filter($statuses, static fn (string $s): bool => $s !== ReproductiveAptitudeStatus::APT->value);

        $this->command?->info(sprintf(
            'FitTestBullsSeeder: %d toros aptos en el lote propio "%s" (id %d).',
            count($caravanIds) - count($notApt),
            $batch->name,
            $batch->id
        ));

        if ($notApt !== []) {
            $this->command?->warn('  Atención: el motor no los dejó aptos -> ' . json_encode($notApt));
        }
    }

    private function resolveOwnBreedingBatch(int $companyId): Batch
    {
        $criaActivity = Activity::withoutGlobalScopes()->where('code', 'CRIA')->first()
            ?? Activity::create(['code' => 'CRIA', 'name' => 'Cría']);

        return Batch::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'name' => self::BATCH_NAME],
            [
                'farm_id' => null,
                'activity_id' => $criaActivity->id,
                'is_active' => true,
                'is_system' => false,
                'observaciones' => 'Lote propio reservado a pruebas: reproductores con examen físico y muestreo venéreo completos, aptos para servicio.',
            ]
        );
    }

    /**
     * @return array<string, int> Caravan id keyed by tag.
     */
    private function resolveBulls(int $companyId, int $batchId): array
    {
        $toroCategory = AnimalCategory::withoutGlobalScopes()->where('code', 'TORO')->first();
        $ids = [];
        $index = 0;

        foreach (array_keys(self::BULLS) as $tag) {
            $caravan = Caravan::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $companyId, 'identification' => $tag],
                [
                    'batch_id' => $batchId,
                    'sex' => 'M',
                    'category_id' => $toroCategory?->id,
                    'teeth' => 4,
                    'entry_weight' => 720.0 + ($index++ * 15),
                    'entry_date' => now()->subMonths(2)->toDateString(),
                ]
            );

            $ids[$tag] = (int) $caravan->id;
        }

        return $ids;
    }

    /**
     * @param list<int> $caravanIds
     */
    private function stripSanitaryHistory(int $companyId, array $caravanIds): void
    {
        // Order matters: findings reference samples, samples reference evaluations.
        VeterinaryDiagnosis::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('caravan_id', $caravanIds)
            ->delete();

        BullLabSample::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('caravan_id', $caravanIds)
            ->delete();

        BullHealthEvaluation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('caravan_id', $caravanIds)
            ->delete();
    }

    /**
     * @param array<string, int> $bulls
     */
    private function recordChuteExaminations(int $companyId, array $bulls): void
    {
        foreach ($bulls as $tag => $caravanId) {
            $exam = self::BULLS[$tag];

            BullHealthEvaluation::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'caravan_id' => $caravanId,
                'last_evaluation_date' => now()->subDays(3)->toDateString(),
                'aplomo_notes' => $exam['aplomo'],
                'scrotal_circumference_cm' => $exam['ce'],
                'body_condition_score' => $exam['cc'],
                'libido' => $exam['libido'],
                // Provisional: the recompute at the end sets the status the engine derives.
                'status' => ReproductiveAptitudeStatus::PENDING_EVALUATION->value,
                'observations' => 'Examen andrológico pre-servicio satisfactorio. Dos raspajes prepuciales negativos.',
            ]);
        }
    }

    private function createSignedAct(int $companyId, Veterinarian $vet, string $number, int $daysAgo): DiagnosticProtocol
    {
        $act = DiagnosticProtocol::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'protocol_number' => $number],
            [
                'protocol_type' => DiagnosticProtocolType::EXTRACTION_ACT->value,
                'parent_protocol_id' => null,
                'veterinarian_id' => $vet->id,
                'sample_date' => now()->subDays($daysAgo)->toDateString(),
                'result_date' => null,
                'source_channel' => DiagnosticSourceChannel::PORTAL_VET->value,
                'status' => ProtocolStatus::CONFIRMED->value,
                'verification_status' => ProtocolVerificationStatus::VERIFIED->value,
                'signed_at' => now()->subDays($daysAgo),
                'signed_by_veterinarian_id' => $vet->id,
                'signed_license_number' => $vet->license_number,
                'signed_veterinarian_name' => $vet->name,
                'observations' => 'Acta de manga de semilla para los toros de prueba aptos.',
            ]
        );

        ExtractionActDetail::updateOrCreate(
            ['protocol_id' => (int) $act->id],
            [
                'institution' => [
                    'nombre' => 'Laboratorio Regional Tandil',
                    'cuit' => '30712345671',
                    'direccion' => 'Ruta 226 km 12, Tandil',
                    'codigo_oficial' => null,
                    'contacto' => null,
                ],
                'destination_plan' => SampleDestinationPlan::IN_SITU->value,
                'dispatch_note_number' => null,
                'dispatched_at' => null,
            ]
        );

        return $act;
    }

    private function createSignedReport(
        int $companyId,
        Veterinarian $vet,
        DiagnosticProtocol $act,
        string $number,
        int $daysAgo
    ): DiagnosticProtocol {
        $resultDaysAgo = $daysAgo - 6;

        return DiagnosticProtocol::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'protocol_number' => $number],
            [
                'protocol_type' => DiagnosticProtocolType::LAB_REPORT->value,
                'parent_protocol_id' => $act->id,
                'veterinarian_id' => $vet->id,
                'sample_date' => now()->subDays($daysAgo)->toDateString(),
                'result_date' => now()->subDays($resultDaysAgo)->toDateString(),
                'source_channel' => DiagnosticSourceChannel::PORTAL_VET->value,
                'status' => ProtocolStatus::CONFIRMED->value,
                'verification_status' => ProtocolVerificationStatus::VERIFIED->value,
                'signed_at' => now()->subDays($resultDaysAgo),
                'signed_by_veterinarian_id' => $vet->id,
                'signed_license_number' => $vet->license_number,
                'signed_veterinarian_name' => $vet->name,
                'observations' => 'Cultivo negativo para Tritrichomonas foetus y Campylobacter fetus sobre el acta ' . $act->protocol_number . '.',
            ]
        );
    }

    /**
     * @param list<int> $caravanIds
     * @param list<int> $pathogenIds
     */
    private function createNegativeTubes(
        int $companyId,
        Veterinarian $vet,
        DiagnosticProtocol $act,
        DiagnosticProtocol $report,
        array $caravanIds,
        array $pathogenIds,
        int $round
    ): void {
        $sampleDate = $act->sample_date instanceof \DateTimeInterface
            ? $act->sample_date->format('Y-m-d')
            : (string) $act->sample_date;

        foreach ($caravanIds as $index => $caravanId) {
            foreach ($pathogenIds as $pathogenId) {
                DB::table('bull_lab_samples')->updateOrInsert(
                    [
                        'extraction_act_id' => $act->id,
                        'caravan_id' => $caravanId,
                        'pathogen_id' => $pathogenId,
                        'sample_round' => $round,
                    ],
                    [
                        'company_id' => $companyId,
                        'diagnostic_protocol_id' => $report->id,
                        // In situ: the tube never travels, so it hangs off no shipment.
                        'sample_shipment_id' => null,
                        'veterinarian_id' => $vet->id,
                        'sample_type' => 'PREPUCE_SCRAPE',
                        'sample_date' => $sampleDate,
                        'extracted_on' => $sampleDate,
                        'tube_number' => sprintf('R-APT%d-%02d', $round, $index + 1),
                        'status' => LabSampleStatus::NEGATIVE_CLEARED->value,
                        'protocol_number' => $report->protocol_number,
                        'result_date' => $report->result_date instanceof \DateTimeInterface
                            ? $report->result_date->format('Y-m-d')
                            : $report->result_date,
                        'notes' => $round . 'º raspaje prepucial negativo.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    private function assignToPortalVeterinarian(int $companyId, Veterinarian $vet, int $batchId): void
    {
        VeterinarianBatchAssignment::withoutGlobalScopes()->updateOrCreate(
            [
                'company_id' => $companyId,
                'veterinarian_id' => $vet->id,
                'batch_id' => $batchId,
                'assigned_at' => now()->toDateString(),
            ],
            ['unassigned_at' => null]
        );
    }
}
