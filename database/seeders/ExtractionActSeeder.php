<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Enums\DiagnosticProtocolType;
use App\Core\Enums\DiagnosticSourceChannel;
use App\Core\Enums\LabSampleStatus;
use App\Core\Enums\ProtocolStatus;
use App\Core\Enums\ProtocolVerificationStatus;
use App\Models\Caravan;
use App\Models\Company;
use App\Core\Enums\SampleDestinationPlan;
use App\Models\DiagnosticProtocol;
use App\Models\ExtractionActDetail;
use App\Models\Pathogen;
use App\Models\Veterinarian;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The four states an extraction act can be found in, so every branch of the portal and of the
 * aptitude engine has real data behind it:
 *
 *   A. DRAFT, unsigned                -> nothing it contains clears a bull (ADR-17)
 *   B. CONFIRMED, tubes pending       -> the professional's "waiting on the laboratory" inbox
 *   C. CONFIRMED + negative report    -> one clean round closed
 *   D. CONFIRMED + positive report    -> derives a clinical finding and disqualifies the bull
 */
class ExtractionActSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();

        if (!$company) {
            $this->command?->warn('ExtractionActSeeder: no hay compañía cargada. Se omite.');

            return;
        }

        $companyId = (int) $company->id;

        // Ordered explicitly: more than one professional holds a portal login now, and an
        // unordered `first()` would let the seeded acts change owner between runs.
        $veterinarian = Veterinarian::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->first()
            ?? Veterinarian::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->orderBy('id')
                ->first();

        if (!$veterinarian) {
            $this->command?->warn('ExtractionActSeeder: no hay profesionales cargados. Se omite.');

            return;
        }


        // Bulls that are NOT the untouched test trio: those must stay without sanitary history.
        $bulls = Caravan::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('sex', ['M', 'MACHO', 'MALE'])
            ->where('identification', 'not like', 'ANTHONY-TORO-TEST-OOX%')
            ->orderBy('id')
            ->limit(8)
            ->get();

        if ($bulls->count() < 4) {
            $this->command?->warn('ExtractionActSeeder: no hay suficientes reproductores. Se omite.');

            return;
        }

        $venerealPathogens = Pathogen::withoutGlobalScopes()
            ->whereIn('code', ['TRITRICHOMONAS_FOETUS', 'CAMPYLOBACTER_FETUS'])
            ->pluck('id')
            ->all();

        if ($venerealPathogens === []) {
            $this->command?->warn('ExtractionActSeeder: faltan patógenos venéreos. Se omite.');

            return;
        }

        $groups = $bulls->chunk(2)->values();
        $created = 0;

        $created += $this->seedDraftAct($companyId, $veterinarian, $groups[0], $venerealPathogens);
        $created += $this->seedAwaitingLabAct($companyId, $veterinarian, $groups[1], $venerealPathogens);

        if ($groups->count() > 2) {
            $created += $this->seedReportedAct(
                $companyId, $veterinarian, $groups[2], $venerealPathogens,
                LabSampleStatus::NEGATIVE_CLEARED, 'ACTA-SEED-NEG', 'LAB-SEED-NEG'
            );
        }

        if ($groups->count() > 3) {
            $created += $this->seedReportedAct(
                $companyId, $veterinarian, $groups[3], $venerealPathogens,
                LabSampleStatus::POSITIVE_DETECTED, 'ACTA-SEED-POS', 'LAB-SEED-POS'
            );
        }

        $this->command?->info("ExtractionActSeeder: {$created} actas de extracción cargadas.");
    }

    private function seedDraftAct(int $companyId, $vet, $bulls, array $pathogens): int
    {
        $act = $this->createAct($companyId, $vet, 'ACTA-SEED-DRAFT', signed: false);
        $this->createTubes($companyId, $act, $vet, $bulls, $pathogens, 1, LabSampleStatus::PENDING_RESULTS, null);

        return 1;
    }

    private function seedAwaitingLabAct(int $companyId, $vet, $bulls, array $pathogens): int
    {
        $act = $this->createAct($companyId, $vet, 'ACTA-SEED-PEND', signed: true);
        $this->createTubes($companyId, $act, $vet, $bulls, $pathogens, 1, LabSampleStatus::PENDING_RESULTS, null);

        return 1;
    }

    private function seedReportedAct(
        int $companyId,
        $vet,
        $bulls,
        array $pathogens,
        LabSampleStatus $outcome,
        string $actNumber,
        string $reportNumber
    ): int {
        $act = $this->createAct($companyId, $vet, $actNumber, signed: true);

        $report = DiagnosticProtocol::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'protocol_number' => $reportNumber],
            [
                'protocol_type' => DiagnosticProtocolType::LAB_REPORT->value,
                'parent_protocol_id' => $act->id,
                'veterinarian_id' => $vet->id,
                'sample_date' => now()->subDays(30)->toDateString(),
                'result_date' => now()->subDays(25)->toDateString(),
                'source_channel' => DiagnosticSourceChannel::PORTAL_VET->value,
                'status' => ProtocolStatus::CONFIRMED->value,
                'verification_status' => ProtocolVerificationStatus::VERIFIED->value,
                'signed_at' => now()->subDays(25),
                'signed_by_veterinarian_id' => $vet->id,
                'signed_license_number' => $vet->license_number,
                'signed_veterinarian_name' => $vet->name,
                'observations' => 'Informe de laboratorio de semilla sobre el acta ' . $act->protocol_number . '.',
            ]
        );

        $this->createTubes($companyId, $act, $vet, $bulls, $pathogens, 1, $outcome, (int) $report->id);

        return 2;
    }

    private function createAct(int $companyId, $vet, string $number, bool $signed): DiagnosticProtocol
    {
        $act = DiagnosticProtocol::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'protocol_number' => $number],
            [
                'protocol_type' => DiagnosticProtocolType::EXTRACTION_ACT->value,
                'parent_protocol_id' => null,
                'veterinarian_id' => $vet->id,
                'sample_date' => now()->subDays(30)->toDateString(),
                'result_date' => null,
                'source_channel' => $signed
                    ? DiagnosticSourceChannel::PORTAL_VET->value
                    : DiagnosticSourceChannel::OWNER_DIGITIZED->value,
                'status' => $signed ? ProtocolStatus::CONFIRMED->value : ProtocolStatus::DRAFT->value,
                'verification_status' => $signed
                    ? ProtocolVerificationStatus::VERIFIED->value
                    : ProtocolVerificationStatus::UNVERIFIED->value,
                'signed_at' => $signed ? now()->subDays(30) : null,
                'signed_by_veterinarian_id' => $signed ? $vet->id : null,
                'signed_license_number' => $signed ? $vet->license_number : null,
                'signed_veterinarian_name' => $signed ? $vet->name : null,
                'observations' => 'Acta de manga de semilla (' . ($signed ? 'firmada' : 'sin firmar') . ').',
            ]
        );

        // ADR-39 / ADR-42: the act's own data lives beside it. Seeded with a declared plan so the
        // two cases that matter — processed in house and sent on — are both reachable from a fresh
        // database instead of only after somebody fills a form.
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
                'destination_plan' => $signed
                    ? SampleDestinationPlan::TO_BE_DERIVED->value
                    : SampleDestinationPlan::UNDECIDED->value,
                'dispatch_note_number' => $signed ? 'REM-' . substr($number, -4) : null,
                'dispatched_at' => $signed ? now()->subDays(29)->toDateString() : null,
            ]
        );

        return $act;
    }

    private function createTubes(
        int $companyId,
        DiagnosticProtocol $act,
        $vet,
        $bulls,
        array $pathogens,
        int $round,
        LabSampleStatus $status,
        ?int $reportId
    ): void {
        foreach ($bulls as $index => $bull) {
            foreach ($pathogens as $pathogenId) {
                DB::table('bull_lab_samples')->updateOrInsert(
                    [
                        'extraction_act_id' => $act->id,
                        'caravan_id' => $bull->id,
                        'pathogen_id' => $pathogenId,
                        'sample_round' => $round,
                    ],
                    [
                        'company_id' => $companyId,
                        'diagnostic_protocol_id' => $reportId,
                        'veterinarian_id' => $vet->id,
                        'sample_type' => 'PREPUCE_SCRAPE',
                        'sample_date' => $act->sample_date,
                        'tube_number' => sprintf('R-%s-%02d', substr($act->protocol_number, -3), $index + 1),
                        'status' => $status->value,
                        'extracted_on' => $act->sample_date,
                        'protocol_number' => $reportId !== null ? $act->protocol_number : null,
                        'result_date' => $status === LabSampleStatus::PENDING_RESULTS
                            ? null
                            : now()->subDays(25)->toDateString(),
                        'notes' => 'Muestra de semilla.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
}
