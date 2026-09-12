<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BullLabSample;
use App\Models\Company;
use App\Models\DiagnosticProtocol;
use App\Models\Pathogen;
use App\Models\ProtocolAttachment;
use App\Models\User;
use App\Models\Veterinarian;
use App\Models\VeterinaryDiagnosis;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns the loose laboratory rows seeded by BullHealthSeeder into a coherent evidentiary
 * chain under the three-level model (ADR-1):
 *
 *   diagnostic_protocols  -> the document          ("LAB-2026-890", signed, with evidence)
 *   bull_lab_samples      -> the assay result      (bull 12, scrape round 2, T. foetus)
 *   veterinary_diagnoses  -> the clinical finding  (only positives)
 *
 * It also backfills the pathogen each determination actually assayed. Without that, the
 * venereal rule of ADR-4 has nothing to count and every seeded bull would fall back to
 * PENDING_EVALUATION, which would make the demo dataset misleading.
 */
class DiagnosticProtocolSeeder extends Seeder
{
    /**
     * Protocol headers matching the `protocol_number` values already present in the
     * laboratory rows, so historical samples get a parent document.
     *
     * @var array<string, array<string, mixed>>
     */
    private const PROTOCOL_DEFINITIONS = [
        'LAB-2025-1048' => ['center' => 'TANDIL', 'vet' => 'MP 4582', 'channel' => 'PORTAL_VET', 'offset_months' => 12, 'lag_days' => 6],
        'LAB-2025-1192' => ['center' => 'TANDIL', 'vet' => 'MP 4582', 'channel' => 'PORTAL_VET', 'offset_months' => 12, 'lag_days' => 21],
        'SERO-2025-440' => ['center' => 'AZUL', 'vet' => 'MP 6710', 'channel' => 'OWNER_DIGITIZED', 'offset_months' => 12, 'lag_days' => 5],
        'SERO-2026-088' => ['center' => 'AZUL', 'vet' => 'MP 6710', 'channel' => 'OWNER_DIGITIZED', 'offset_months' => 6, 'lag_days' => 4],
        'LAB-2026-890' => ['center' => 'TANDIL', 'vet' => 'MP 4582', 'channel' => 'PORTAL_VET', 'offset_days' => 20, 'lag_days' => 6],
        'LAB-2026-920' => ['center' => 'TANDIL', 'vet' => 'MP 4582', 'channel' => 'PORTAL_VET', 'offset_days' => 10, 'lag_days' => 6],
        'SERO-2026-701' => ['center' => 'AZUL', 'vet' => 'MP 6710', 'channel' => 'OWNER_DIGITIZED', 'offset_days' => 10, 'lag_days' => 6],
        'LAB-2026-894' => ['center' => 'TANDIL', 'vet' => 'MP 4582', 'channel' => 'PORTAL_VET', 'offset_days' => 18, 'lag_days' => 6],
        'LAB-2026-895' => ['center' => 'TANDIL', 'vet' => 'MP 4582', 'channel' => 'PORTAL_VET', 'offset_days' => 18, 'lag_days' => 6],
        'SERO-2026-999' => ['center' => 'AZUL', 'vet' => 'MP 6710', 'channel' => 'OWNER_DIGITIZED', 'offset_days' => 15, 'lag_days' => 5],
    ];

    public function run(): void
    {
        $company = Company::first();

        if (!$company) {
            $this->command?->warn('DiagnosticProtocolSeeder: no hay compañía cargada. Se omite.');

            return;
        }

        $companyId = (int) $company->id;

        $pathogens = Pathogen::all()->keyBy('code');
        $veterinarians = Veterinarian::withoutGlobalScopes()->where('company_id', $companyId)->get()->keyBy('license_number');

        if ($veterinarians->isEmpty()) {
            $this->command?->warn('DiagnosticProtocolSeeder: faltan los profesionales. Ejecute VeterinaryCatalogSeeder primero.');

            return;
        }

        $centerAliases = [];

        $protocols = $this->seedProtocolHeaders($companyId, $veterinarians, $centerAliases);
        $this->backfillPathogens($companyId, $pathogens);
        $linked = $this->linkSamplesToProtocols($companyId, $protocols);
        $this->linkDerivedFindings($companyId, $protocols);
        $this->seedDigitizedProtocolWithEvidence($companyId, $veterinarians, $centerAliases, $pathogens);
        $this->seedVoidedProtocol($companyId, $veterinarians, $centerAliases);
        $this->normaliseCustodyOfHistoricalSamples($companyId);

        $this->command?->info(sprintf(
            'DiagnosticProtocolSeeder: %d protocolos creados, %d determinaciones vinculadas.',
            count($protocols),
            $linked
        ));
    }

    /**
     * The historical rows this seeder loads predate the custody model (ADR-20 / ADR-26), and
     * they are written straight to the table rather than through the use cases, so they arrive
     * with no arrival declared and no per-tube date.
     *
     * Only the per-tube date is backfilled now: with the arrival axis gone (ADR-30), a tube
     * that was never dispatched simply has no shipment, which is a legitimate state.
     */
    private function normaliseCustodyOfHistoricalSamples(int $companyId): void
    {
        DB::table('bull_lab_samples')
            ->where('company_id', $companyId)
            ->whereNull('extracted_on')
            ->update(['extracted_on' => DB::raw('sample_date')]);


    }

    /**
     * @param \Illuminate\Support\Collection<string, Veterinarian> $veterinarians
     * @return array<string, int> Protocol ids keyed by protocol number.
     */
    private function seedProtocolHeaders(int $companyId, $veterinarians, array $centers): array
    {
        $ids = [];

        foreach (self::PROTOCOL_DEFINITIONS as $protocolNumber => $definition) {
            $vet = $veterinarians->get($definition['vet']);
            $center = $centers[$definition['center']] ?? null;

            if ($vet === null) {
                continue;
            }

            $sampleDate = isset($definition['offset_months'])
                ? now()->subMonths((int) $definition['offset_months'])
                : now()->subDays((int) $definition['offset_days']);

            $resultDate = (clone $sampleDate)->addDays((int) $definition['lag_days']);

            $model = DiagnosticProtocol::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $companyId, 'protocol_number' => $protocolNumber],
                [
                    'veterinarian_id' => $vet->id,
                    'sample_date' => $sampleDate->toDateString(),
                    'result_date' => $resultDate->toDateString(),
                    'source_channel' => $definition['channel'],
                    'status' => 'CONFIRMED',
                    // ADR-9: a portal submission is signed by the professional; a transcription is not.
                    'verification_status' => $definition['channel'] === 'PORTAL_VET' ? 'VERIFIED' : 'UNVERIFIED',
                    'signed_at' => $definition['channel'] === 'PORTAL_VET' ? $resultDate->toDateTimeString() : null,
                    'signed_by_veterinarian_id' => $definition['channel'] === 'PORTAL_VET' ? $vet->id : null,
                    // ADR-8: frozen copy, not a live join against the catalogue.
                    'signed_license_number' => $definition['channel'] === 'PORTAL_VET' ? $vet->license_number : null,
                    'signed_veterinarian_name' => $definition['channel'] === 'PORTAL_VET' ? $vet->name : null,
                    'observations' => 'Protocolo de campaña pre-servicio.',
                    'created_by_user_id' => User::first()?->id,
                ]
            );

            $ids[$protocolNumber] = (int) $model->id;
        }

        return $ids;
    }

    /**
     * BullHealthSeeder writes negative determinations without a pathogen. The aptitude engine
     * counts negative rounds PER pathogen, so each preputial scrape is resolved into the two
     * venereal agents it actually rules out, and serology into Brucella.
     *
     * @param \Illuminate\Support\Collection<string, Pathogen> $pathogens
     */
    private function backfillPathogens(int $companyId, $pathogens): void
    {
        $trichomonas = $pathogens->get('TRITRICHOMONAS_FOETUS');
        $campylobacter = $pathogens->get('CAMPYLOBACTER_FETUS');
        $brucella = $pathogens->get('BRUCELLA_ABORTUS');

        if (!$trichomonas || !$campylobacter || !$brucella) {
            return;
        }

        $orphans = BullLabSample::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('pathogen_id')
            ->get();

        foreach ($orphans as $sample) {
            if ($sample->sample_type === 'BLOOD_SEROLOGY') {
                $sample->update(['pathogen_id' => $brucella->id]);

                continue;
            }

            $sample->update(['pathogen_id' => $trichomonas->id]);

            // The same scrape rules out both venereal agents; the engine needs one row per agent.
            $twin = $sample->replicate(['id']);
            $twin->pathogen_id = $campylobacter->id;
            $twin->tube_number = $sample->tube_number !== null ? $sample->tube_number . '-C' : null;
            $twin->notes = 'Cultivo de la misma muestra para Campylobacter fetus subsp. venerealis.';
            $twin->save();
        }
    }

    /**
     * @param array<string, int> $protocols
     */
    private function linkSamplesToProtocols(int $companyId, array $protocols): int
    {
        $linked = 0;

        foreach ($protocols as $protocolNumber => $protocolId) {
            $linked += BullLabSample::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('protocol_number', $protocolNumber)
                ->whereNull('diagnostic_protocol_id')
                ->update([
                    'diagnostic_protocol_id' => $protocolId,
                    'veterinarian_id' => DiagnosticProtocol::withoutGlobalScopes()->find($protocolId)?->veterinarian_id,
                ]);
        }

        return $linked;
    }

    /**
     * Positive determinations already seeded as diagnoses are traced back to the report and
     * the sample that produced them (ADR-1 derivation rule).
     *
     * @param array<string, int> $protocols
     */
    private function linkDerivedFindings(int $companyId, array $protocols): void
    {
        $positives = BullLabSample::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'POSITIVE_DETECTED')
            ->whereNotNull('diagnostic_protocol_id')
            ->get();

        foreach ($positives as $sample) {
            VeterinaryDiagnosis::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('caravan_id', $sample->caravan_id)
                ->where('pathogen_id', $sample->pathogen_id)
                ->whereNull('diagnostic_protocol_id')
                ->update([
                    'diagnostic_protocol_id' => $sample->diagnostic_protocol_id,
                    'bull_lab_sample_id' => $sample->id,
                    'veterinarian_id' => $sample->veterinarian_id,
                ]);
        }

        unset($protocols);
    }

    /**
     * The canonical Use Case 2 example: a photo forwarded over WhatsApp, transcribed by the
     * producer, archived as CONFIRMED but UNVERIFIED, with the original image stored on the
     * private tenant disk and reachable only through a signed URL.
     *
     * @param \Illuminate\Support\Collection<string, Veterinarian> $veterinarians
     * @param \Illuminate\Support\Collection<string, Pathogen> $pathogens
     */
    private function seedDigitizedProtocolWithEvidence(int $companyId, $veterinarians, array $centers, $pathogens): void
    {
        $vet = $veterinarians->get('MP 6710');
        $center = $centers['AZUL'] ?? null;

        if ($vet === null) {
            return;
        }

        $protocol = DiagnosticProtocol::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'protocol_number' => 'LAB-2026-8492'],
            [
                'veterinarian_id' => $vet->id,
                'sample_date' => now()->subDays(9)->toDateString(),
                'result_date' => now()->subDays(3)->toDateString(),
                'source_channel' => 'OWNER_DIGITIZED',
                'status' => 'CONFIRMED',
                'verification_status' => 'UNVERIFIED',
                'observations' => 'Informe remitido por WhatsApp y transcripto por el capataz. Pendiente de aval profesional.',
                'created_by_user_id' => User::first()?->id,
            ]
        );

        $this->seedEvidenceFile($companyId, (int) $protocol->id);

        unset($pathogens);
    }

    private function seedEvidenceFile(int $companyId, int $protocolId): void
    {
        if (ProtocolAttachment::withoutGlobalScopes()->where('diagnostic_protocol_id', $protocolId)->exists()) {
            return;
        }

        // Minimal but genuinely valid JPEG, so the demo exercises the real download path
        // instead of a placeholder row pointing at nothing.
        $jpeg = base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            . 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
            . 'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='
        );

        $disk = Storage::disk((string) config('livestock.attachments.disk', 'tenant'));
        $tenantSegment = function_exists('tenant') && tenant('id') ? Str::slug((string) tenant('id')) : 'central';
        $path = sprintf('%s/companies/%d/protocols/%d/%s.jpg', $tenantSegment, $companyId, $protocolId, (string) Str::uuid());

        $disk->put($path, $jpeg);

        ProtocolAttachment::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'diagnostic_protocol_id' => $protocolId,
            'file_path' => $path,
            'file_name' => 'informe_lab_8492_whatsapp.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => strlen($jpeg),
            'checksum_sha256' => hash('sha256', $jpeg),
            'needs_conversion' => false,
            'uploaded_by_user_id' => User::first()?->id,
        ]);
    }

    /**
     * Use Case 3 example, so the correction flow has something to show from the first run.
     *
     * @param \Illuminate\Support\Collection<string, Veterinarian> $veterinarians
     */
    private function seedVoidedProtocol(int $companyId, $veterinarians, array $centers): void
    {
        $vet = $veterinarians->get('MP 3391');
        $center = $centers['RURAL_SUR'] ?? null;

        if ($vet === null) {
            return;
        }

        DiagnosticProtocol::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'protocol_number' => 'LAB-2026-7711-ANULADO'],
            [
                'veterinarian_id' => $vet->id,
                'sample_date' => now()->subDays(30)->toDateString(),
                'result_date' => now()->subDays(24)->toDateString(),
                'source_channel' => 'OWNER_DIGITIZED',
                'status' => 'VOIDED',
                'verification_status' => 'UNVERIFIED',
                'observations' => 'Transcripción con caravanas cruzadas.',
                'voided_at' => now()->subDays(20),
                'voided_by_user_id' => User::first()?->id,
                'void_reason' => 'Error de transcripción: se cargaron resultados sobre caravanas equivocadas. Reemitido en LAB-2026-8492.',
                'created_by_user_id' => User::first()?->id,
            ]
        );
    }
}
