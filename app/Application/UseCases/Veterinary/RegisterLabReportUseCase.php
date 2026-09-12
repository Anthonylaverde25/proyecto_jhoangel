<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Application\DTOs\Veterinary\LabReportLineDTO;
use App\Application\DTOs\Veterinary\ProtocolAttachmentUploadDTO;
use App\Application\DTOs\Veterinary\RegisterLabReportDTO;
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
use App\Core\Interfaces\IProtocolAttachmentStorage;
use App\Core\Interfaces\IVeterinarianRepository;
use App\Core\ValueObjects\InstitutionMeta;
use App\Models\BullLabSample;
use App\Application\Services\PersistProtocolDetailsService;
use App\Models\DiagnosticProtocol;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ADR-11: the laboratory speaks, as its own document.
 *
 * The report is a child of the extraction act, carries the number printed on the physical
 * report, and resolves tubes that already exist — it never mints new ones, because the tube's
 * identity was fixed at the chute and that is the whole point of the chain of custody.
 */
final class RegisterLabReportUseCase
{
    public function __construct(
        private readonly IDiagnosticProtocolRepository $protocolRepository,
        private readonly IVeterinarianRepository $veterinarianRepository,
        private readonly RecomputeBullAptitudeBulkService $recomputeAptitude,
        private readonly IProtocolAttachmentStorage $storage,
        private readonly PersistProtocolDetailsService $protocolDetails
    ) {
    }

    /**
     * @throws VeterinaryDomainException
     */
    public function __invoke(RegisterLabReportDTO $dto): DiagnosticProtocolEntity
    {
        if ($dto->labReportNumber === '') {
            throw VeterinaryDomainException::domainError('El informe de laboratorio necesita su número de protocolo.');
        }

        if ($dto->lines === []) {
            throw VeterinaryDomainException::domainError('El informe no contiene determinaciones.');
        }

        $act = $this->protocolRepository->findById($dto->extractionActId, $dto->companyId);

        if ($act === null) {
            throw VeterinaryDomainException::actNotFound($dto->extractionActId);
        }

        if (!$act->isExtractionAct()) {
            throw VeterinaryDomainException::protocolTypeMismatch(
                DiagnosticProtocolType::EXTRACTION_ACT->value,
                $act->getProtocolType()->value
            );
        }

        // ADR-11: the only gate left is the signature. v7 also demanded a registered arrival;
        // that concept is gone, because the samples may never have left the professional's
        // hands at all and demanding a declaration of arrival asked for a fact nobody witnessed.
        if (!$act->canReceiveLabReport()) {
            throw VeterinaryDomainException::actNotSigned($act->getProtocolNumber());
        }

        $existingReport = $this->protocolRepository->findLabReportForAct($dto->extractionActId, $dto->companyId);

        if ($existingReport !== null) {
            throw VeterinaryDomainException::labReportAlreadyIssued(
                $act->getProtocolNumber(),
                $existingReport->getProtocolNumber()
            );
        }

        if ($this->protocolRepository->existsByProtocolNumber($dto->labReportNumber, $dto->companyId)) {
            throw VeterinaryDomainException::duplicateProtocolNumber($dto->labReportNumber);
        }

        $veterinarian = $this->veterinarianRepository->findById($dto->veterinarianId, $dto->companyId);

        if ($veterinarian === null) {
            throw VeterinaryDomainException::veterinarianNotFound($dto->veterinarianId);
        }

        $samples = $this->loadActSamples($dto, $act->getProtocolNumber());

        $snapshot = $veterinarian->toSignatureSnapshot();

        // ADR-29: both institutions are described here, once, by the only person who knows them.
        // No catalogue row backs either and none is needed.
        $reportingInstitution = InstitutionMeta::fromNullableArray($dto->reportingInstitution);

        if ($reportingInstitution === null) {
            throw VeterinaryDomainException::reportingInstitutionRequired();
        }

        // ADR-31 (rev.): the professional says whether the tubes were processed somewhere else.
        // No CUIT comparison can answer this — an employee's CUIT differs from their laboratory's
        // and nothing was derived — so the declaration is the only source, and the second block
        // is meaningless without it.
        $isDerived = $dto->isDerived;
        $analysingInstitution = $isDerived
            ? InstitutionMeta::fromNullableArray($dto->analysingInstitution)
            : null;

        if ($isDerived && $analysingInstitution === null) {
            throw VeterinaryDomainException::derivationRequiresAnalysingInstitution();
        }

        // ADR-35 (rev.): a derived result is a transcription of somebody else's paper, and that
        // paper is the only thing holding it up.
        if ($isDerived
            && (bool) config('livestock.custody.analysis_report_requires_attachment', true)
            && $dto->attachments === []
        ) {
            throw VeterinaryDomainException::analysisReportRequiresAttachment($analysingInstitution->getName());
        }

        // ADR-9: a transcription of somebody else's analysis does not weigh the same as a report
        // the professional produced on their own bench.
        $sourceChannel = $isDerived
            ? DiagnosticSourceChannel::OWNER_DIGITIZED
            : DiagnosticSourceChannel::PORTAL_VET;

        $writtenPaths = [];

        try {
            return DB::transaction(function () use ($dto, $act, $samples, $snapshot, $reportingInstitution, $analysingInstitution, $isDerived, $sourceChannel, &$writtenPaths): DiagnosticProtocolEntity {
                $report = DiagnosticProtocol::create([
                    'company_id' => $dto->companyId,
                    'protocol_number' => $dto->labReportNumber,
                    'protocol_type' => DiagnosticProtocolType::LAB_REPORT->value,
                    'parent_protocol_id' => $dto->extractionActId,
                    'veterinarian_id' => $dto->veterinarianId,
                    // The samples were drawn on the act's date; only the result is new.
                    'sample_date' => $act->getSampleDate()->format('Y-m-d'),
                    'result_date' => $dto->resultDate,
                    'source_channel' => $sourceChannel->value,
                    'status' => ProtocolStatus::CONFIRMED->value,
                    'verification_status' => $sourceChannel->defaultVerificationStatus()->value,
                    'signed_at' => Carbon::now(),
                    'signed_by_veterinarian_id' => $dto->veterinarianId,
                    'signed_license_number' => $snapshot['license_number'],
                    'signed_veterinarian_name' => $snapshot['name'],
                    'signed_cuit' => $snapshot['cuit'],
                    'signed_billing_cuit' => $snapshot['billing_cuit'],
                    'observations' => $dto->observations
                        ?? 'Informe de laboratorio sobre el acta ' . $act->getProtocolNumber() . '.',
                    'created_by_user_id' => $dto->createdByUserId,
                ]);

                $reportId = (int) $report->id;

                // ADR-42: the report's own data, in its own table.
                $this->protocolDetails->createReportDetail(
                    protocolId: $reportId,
                    reportingInstitution: $reportingInstitution,
                    analysingInstitution: $analysingInstitution,
                    isDerived: $isDerived
                );

                $writtenPaths = $this->persistAttachments($dto, $reportId);
                $this->resolveSamples($dto, $reportId);
                $this->deriveDiagnoses($dto, $reportId, $act->getProtocolNumber());

                $caravanIds = array_values(array_unique(array_map(
                    static fn ($sample): int => (int) $sample->caravan_id,
                    $samples
                )));

                if ($caravanIds !== []) {
                    $this->recomputeAptitude->__invoke($caravanIds, $dto->companyId, $dto->resultDate);
                }

                $entity = $this->protocolRepository->findById($reportId, $dto->companyId);

                if ($entity === null) {
                    throw VeterinaryDomainException::protocolNotFound($reportId);
                }

                return $entity;
            });
        } catch (Throwable $exception) {
            // ADR-10 compensating action: the transaction is gone, the files are not.
            foreach ($writtenPaths as $path) {
                $this->storage->delete($path);
            }

            throw $exception;
        }
    }

    /**
     * ADR-27: the laboratory's own PDF, stored next to the transcription that cites it.
     *
     * @return list<string> Paths written, so a failed transaction can undo them.
     */
    private function persistAttachments(RegisterLabReportDTO $dto, int $reportId): array
    {
        $writtenPaths = [];
        $rows = [];

        /** @var ProtocolAttachmentUploadDTO $attachment */
        foreach ($dto->attachments as $attachment) {
            $path = $this->storage->store($dto->companyId, $reportId, $attachment->contents, $attachment->extension);
            $writtenPaths[] = $path;

            $rows[] = [
                'company_id' => $dto->companyId,
                'diagnostic_protocol_id' => $reportId,
                'file_path' => $path,
                'file_name' => $attachment->fileName,
                'mime_type' => $attachment->mimeType,
                'file_size' => $attachment->sizeBytes,
                'checksum_sha256' => $attachment->checksum(),
                'needs_conversion' => $attachment->needsConversion(),
                'uploaded_by_user_id' => $dto->createdByUserId,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        }

        if ($rows !== []) {
            DB::table('protocol_attachments')->insert($rows);
        }

        return $writtenPaths;
    }

    /**
     * Every reported tube must belong to this act. Otherwise a caller could resolve somebody
     * else's samples by guessing ids.
     *
     * @return array<int, \stdClass>
     */
    private function loadActSamples(RegisterLabReportDTO $dto, string $actNumber): array
    {
        $samples = DB::table('bull_lab_samples')
            ->where('company_id', $dto->companyId)
            ->where('extraction_act_id', $dto->extractionActId)
            ->whereIn('id', $dto->sampleIds())
            ->get(['id', 'caravan_id', 'pathogen_id'])
            ->keyBy('id')
            ->all();

        foreach ($dto->sampleIds() as $sampleId) {
            if (!isset($samples[$sampleId])) {
                throw VeterinaryDomainException::sampleNotInAct($sampleId, $actNumber);
            }

        }

        return $samples;
    }

    private function resolveSamples(RegisterLabReportDTO $dto, int $reportId): void
    {
        $now = Carbon::now();

        /** @var LabReportLineDTO $line */
        foreach ($dto->lines as $line) {
            if (!$line->isResolved()) {
                continue;
            }

            BullLabSample::query()
                ->where('id', $line->sampleId)
                ->where('company_id', $dto->companyId)
                ->where('extraction_act_id', $dto->extractionActId)
                ->update([
                    'diagnostic_protocol_id' => $reportId,
                    'protocol_number' => $dto->labReportNumber,
                    'status' => $line->status->value,
                    'result_date' => $dto->resultDate,
                    'notes' => $line->notes ?? 'Resultado informado por el laboratorio.',
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * ADR-2: only a positive determination becomes a clinical finding. Negatives live on in
     * `bull_lab_samples` and nowhere else.
     */
    private function deriveDiagnoses(RegisterLabReportDTO $dto, int $reportId, string $actNumber): void
    {
        $positives = DB::table('bull_lab_samples')
            ->where('company_id', $dto->companyId)
            ->where('diagnostic_protocol_id', $reportId)
            ->where('status', LabSampleStatus::POSITIVE_DETECTED->value)
            ->get(['id', 'caravan_id', 'pathogen_id']);

        if ($positives->isEmpty()) {
            return;
        }

        $now = Carbon::now();
        $rows = [];

        foreach ($positives as $sample) {
            $rows[] = [
                'company_id' => $dto->companyId,
                'diagnostic_protocol_id' => $reportId,
                'bull_lab_sample_id' => (int) $sample->id,
                'caravan_id' => (int) $sample->caravan_id,
                'pathogen_id' => (int) $sample->pathogen_id,
                'veterinarian_id' => $dto->veterinarianId,
                'diagnosed_by_user_id' => $dto->createdByUserId,
                'diagnosis_date' => $dto->resultDate,
                'status' => DiagnosisStatus::CONFIRMED_POSITIVE->value,
                'resolution_date' => null,
                'treatment_notes' => 'Hallazgo positivo informado sobre el acta ' . $actNumber . '.',
                'source_context' => 'PRE_SERVICE',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('veterinary_diagnoses')->insert($rows);
    }

}
