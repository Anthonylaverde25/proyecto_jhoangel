<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Application\DTOs\Veterinary\PortalBullEvaluationLineDTO;
use App\Application\DTOs\Veterinary\ProcessVetPortalEvaluationDTO;
use App\Application\DTOs\Veterinary\ProtocolSampleLineDTO;
use App\Application\Services\RecomputeBullAptitudeBulkService;
use App\Core\Entities\DiagnosticProtocolEntity;
use App\Core\Enums\DiagnosisStatus;
use App\Core\Enums\DiagnosticProtocolType;
use App\Core\Enums\DiagnosticSourceChannel;
use App\Core\Enums\LabSampleStatus;
use App\Core\Enums\ProtocolStatus;
use App\Core\Enums\ProtocolVerificationStatus;
use App\Core\Exceptions\VeterinaryDomainException;
use App\Core\Interfaces\IDiagnosticProtocolRepository;
use App\Core\Interfaces\IVeterinarianRepository;
use App\Models\Caravan;
use App\Application\Services\PersistProtocolDetailsService;
use App\Core\Enums\SampleDestinationPlan;
use App\Models\DiagnosticProtocol;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Use Case 1 — Direct veterinary portal, autonomous variant.
 *
 * The professional works entirely inside the portal with no chute sheet behind them: they record
 * biometry and sampling themselves and sign on the spot, so the document is born CONFIRMED +
 * VERIFIED with the signature frozen onto it (ADR-8).
 *
 * It emits an EXTRACTION_ACT because that is what it is — the professional certifying what came
 * out of which animal. When a chute sheet already opened an act, this is NOT the path: the portal
 * signs that act (SignExtractionActUseCase) and reports on it (RegisterLabReportUseCase), so one
 * chute session can never produce two acts with different numbers.
 */
final class ProcessVetPortalEvaluationUseCase
{
    public function __construct(
        private readonly IDiagnosticProtocolRepository $protocolRepository,
        private readonly IVeterinarianRepository $veterinarianRepository,
        private readonly RecomputeBullAptitudeBulkService $recomputeAptitude,
        private readonly PersistProtocolDetailsService $protocolDetails
    ) {
    }

    /**
     * @throws VeterinaryDomainException
     */
    public function __invoke(ProcessVetPortalEvaluationDTO $dto): DiagnosticProtocolEntity
    {
        if ($dto->bulls === []) {
            throw VeterinaryDomainException::domainError('La evaluación debe incluir al menos un reproductor.');
        }

        if ($dto->protocolNumber === '') {
            throw VeterinaryDomainException::domainError('El número de protocolo es obligatorio.');
        }

        $veterinarian = $this->veterinarianRepository->findById($dto->veterinarianId, $dto->companyId);

        if ($veterinarian === null) {
            throw VeterinaryDomainException::veterinarianNotFound($dto->veterinarianId);
        }

        $existing = $this->protocolRepository->findByProtocolNumber($dto->protocolNumber, $dto->companyId);

        if ($existing !== null) {
            throw VeterinaryDomainException::duplicateProtocolNumber($dto->protocolNumber, $existing->getId());
        }

        $this->assertBullsBelongToBatch($dto);

        $snapshot = $veterinarian->toSignatureSnapshot();

        return DB::transaction(function () use ($dto, $snapshot): DiagnosticProtocolEntity {
            $protocol = DiagnosticProtocol::create([
                'company_id' => $dto->companyId,
                'protocol_number' => $dto->protocolNumber,
                'protocol_type' => DiagnosticProtocolType::EXTRACTION_ACT->value,
                'veterinarian_id' => $dto->veterinarianId,
                'sample_date' => $dto->sampleDate,
                'result_date' => $dto->resultDate,
                'source_channel' => DiagnosticSourceChannel::PORTAL_VET->value,
                'status' => ProtocolStatus::CONFIRMED->value,
                'verification_status' => ProtocolVerificationStatus::VERIFIED->value,
                'signed_at' => Carbon::now()->toDateTimeString(),
                'signed_by_veterinarian_id' => $dto->veterinarianId,
                'signed_license_number' => $snapshot['license_number'],
                'signed_veterinarian_name' => $snapshot['name'],
                'observations' => $dto->observations,
                'created_by_user_id' => $dto->createdByUserId,
            ]);

            $protocolId = (int) $protocol->id;

            // ADR-42: every act carries its detail row. This legacy endpoint collects no
            // institution, so it records what is true — nothing was declared.
            $this->protocolDetails->upsertActDetail(
                protocolId: $protocolId,
                institution: null,
                destinationPlan: SampleDestinationPlan::default()
            );

            $this->persistBiometry($dto);
            $this->persistSamplesAndFindings($dto, $protocolId);

            $this->recomputeAptitude->__invoke($dto->caravanIds(), $dto->companyId, $dto->resultDate);

            $entity = $this->protocolRepository->findById($protocolId, $dto->companyId);

            if ($entity === null) {
                throw VeterinaryDomainException::protocolNotFound($protocolId);
            }

            return $entity;
        });
    }

    /**
     * The portal only exposes the troop assigned to the professional; this re-checks the
     * claim server side, so a hand crafted payload cannot reach another batch's animals.
     */
    private function assertBullsBelongToBatch(ProcessVetPortalEvaluationDTO $dto): void
    {
        $caravanIds = $dto->caravanIds();

        $eligible = Caravan::query()
            ->where('company_id', $dto->companyId)
            ->where('batch_id', $dto->batchId)
            ->whereIn('id', $caravanIds)
            ->whereIn('sex', ['M', 'MACHO', 'MALE'])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $rejected = array_diff($caravanIds, $eligible);

        if ($rejected !== []) {
            throw VeterinaryDomainException::caravanNotOwnedOrNotMale(implode(', ', $rejected));
        }
    }

    /**
     * A new longitudinal evaluation row per bull. The status written here is provisional:
     * RecomputeBullAptitudeBulkService overwrites it once the samples are in.
     */
    private function persistBiometry(ProcessVetPortalEvaluationDTO $dto): void
    {
        $now = Carbon::now();
        $rows = [];

        /** @var PortalBullEvaluationLineDTO $bull */
        foreach ($dto->bulls as $bull) {
            if (!$bull->hasBiometry()) {
                continue;
            }

            $rows[] = [
                'company_id' => $dto->companyId,
                'caravan_id' => $bull->caravanId,
                'last_evaluation_date' => $dto->sampleDate,
                'aplomo_notes' => $bull->aplomoNotes,
                'scrotal_circumference_cm' => $bull->scrotalCircumferenceCm,
                'body_condition_score' => $bull->bodyConditionScore,
                'libido' => $bull->libido,
                'status' => 'PENDING_EVALUATION',
                'observations' => $bull->observations,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('bull_health_evaluations')->insert($rows);
        }
    }

    private function persistSamplesAndFindings(ProcessVetPortalEvaluationDTO $dto, int $protocolId): void
    {
        $now = Carbon::now();
        $sampleRows = [];

        /** @var PortalBullEvaluationLineDTO $bull */
        foreach ($dto->bulls as $bull) {
            /** @var ProtocolSampleLineDTO $line */
            foreach ($bull->samples as $line) {
                $sampleRows[] = [
                    'company_id' => $dto->companyId,
                    'caravan_id' => $bull->caravanId,
                    // The professional both drew and reported these tubes in one act, so the
                    // document is at once their origin and the record that resolved them.
                    'extraction_act_id' => $protocolId,
                    'diagnostic_protocol_id' => $protocolId,
                    'veterinarian_id' => $dto->veterinarianId,
                    'evaluation_id' => null,
                    'sample_type' => $line->sampleType,
                    'sample_round' => $line->sampleRound,
                    'sample_date' => $dto->sampleDate,
                    'tube_number' => $line->tubeNumber,
                    'status' => $line->status,
                    'protocol_number' => $dto->protocolNumber,
                    'result_date' => $line->status === LabSampleStatus::PENDING_RESULTS->value ? null : $dto->resultDate,
                    'pathogen_id' => $line->pathogenId,
                    'notes' => $line->notes,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($sampleRows === []) {
            return;
        }

        DB::table('bull_lab_samples')->insert($sampleRows);

        $positiveSamples = DB::table('bull_lab_samples')
            ->where('diagnostic_protocol_id', $protocolId)
            ->where('status', LabSampleStatus::POSITIVE_DETECTED->value)
            ->get(['id', 'caravan_id', 'pathogen_id']);

        if ($positiveSamples->isEmpty()) {
            return;
        }

        $findingRows = [];

        foreach ($positiveSamples as $sample) {
            $findingRows[] = [
                'company_id' => $dto->companyId,
                'diagnostic_protocol_id' => $protocolId,
                'bull_lab_sample_id' => (int) $sample->id,
                'caravan_id' => (int) $sample->caravan_id,
                'pathogen_id' => (int) $sample->pathogen_id,
                'veterinarian_id' => $dto->veterinarianId,
                'diagnosed_by_user_id' => $dto->createdByUserId,
                'diagnosis_date' => $dto->resultDate,
                'status' => DiagnosisStatus::CONFIRMED_POSITIVE->value,
                'resolution_date' => null,
                'treatment_notes' => 'Hallazgo positivo registrado en manga, protocolo ' . $dto->protocolNumber . '.',
                'source_context' => 'PRE_SERVICE',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('veterinary_diagnoses')->insert($findingRows);
    }
}
