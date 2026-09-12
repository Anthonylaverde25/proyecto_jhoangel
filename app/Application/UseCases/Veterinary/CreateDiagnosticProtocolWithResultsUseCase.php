<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Application\DTOs\Veterinary\CreateDiagnosticProtocolDTO;
use App\Application\DTOs\Veterinary\ProtocolAttachmentUploadDTO;
use App\Application\DTOs\Veterinary\ProtocolSampleLineDTO;
use App\Application\Services\RecomputeBullAptitudeBulkService;
use App\Core\Entities\DiagnosticProtocolEntity;
use App\Core\Enums\DiagnosticSourceChannel;
use App\Core\Enums\DiagnosisStatus;
use App\Core\Enums\LabSampleStatus;
use App\Core\Enums\ProtocolStatus;
use App\Core\Exceptions\VeterinaryDomainException;
use App\Core\Interfaces\IDiagnosticProtocolRepository;
use App\Core\Interfaces\IProtocolAttachmentStorage;
use App\Core\Interfaces\IVeterinarianRepository;
use App\Models\Caravan;
use App\Models\DiagnosticProtocol;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Use Case 2 — Assisted digitisation of external evidence (WhatsApp / PDF).
 *
 * ADR-10 order of operations:
 *   1. Validate the whole payload BEFORE touching disk or database.
 *   2. Write the files, accumulating their paths.
 *   3. One transaction: protocol -> samples -> derived findings -> bulk aptitude recompute.
 *   4. On failure: roll the transaction back AND delete the written files, so no orphan
 *      evidence is left behind on disk.
 */
final class CreateDiagnosticProtocolWithResultsUseCase
{
    public function __construct(
        private readonly IDiagnosticProtocolRepository $protocolRepository,
        private readonly IVeterinarianRepository $veterinarianRepository,
        private readonly IProtocolAttachmentStorage $storage,
        private readonly RecomputeBullAptitudeBulkService $recomputeAptitude
    ) {
    }

    /**
     * @throws VeterinaryDomainException
     */
    public function __invoke(CreateDiagnosticProtocolDTO $dto): DiagnosticProtocolEntity
    {
        $sourceChannel = DiagnosticSourceChannel::from($dto->sourceChannel);

        $this->assertPayloadIsCoherent($dto, $sourceChannel);
        $this->assertProtocolNumberIsFree($dto);
        $this->assertCaravansAreEligible($dto);

        $signature = $this->resolveSignature($dto);

        $writtenPaths = [];

        try {
            return DB::transaction(function () use ($dto, $sourceChannel, $signature, &$writtenPaths): DiagnosticProtocolEntity {
                $protocol = DiagnosticProtocol::create([
                    'company_id' => $dto->companyId,
                    'protocol_number' => $dto->protocolNumber,
                    'veterinarian_id' => $dto->veterinarianId,
                    'sample_date' => $dto->sampleDate,
                    'result_date' => $dto->resultDate,
                    'source_channel' => $sourceChannel->value,
                    // The transcription is complete and auditable, hence CONFIRMED; but it was typed
                    // by the producer, so it stays UNVERIFIED until a professional endorses it (ADR-9).
                    'status' => ProtocolStatus::CONFIRMED->value,
                    'verification_status' => $sourceChannel->defaultVerificationStatus()->value,
                    'signed_at' => $signature['signed_at'],
                    'signed_by_veterinarian_id' => $signature['signed_by_veterinarian_id'],
                    'signed_license_number' => $signature['signed_license_number'],
                    'signed_veterinarian_name' => $signature['signed_veterinarian_name'],
                    'observations' => $dto->observations,
                    'created_by_user_id' => $dto->createdByUserId,
                ]);

                $protocolId = (int) $protocol->id;

                $writtenPaths = $this->persistAttachments($dto, $protocolId);
                $this->persistSamplesAndFindings($dto, $protocolId);

                // ADR-10: single bulk pass instead of one findByCaravanId per bull.
                $this->recomputeAptitude->__invoke($dto->caravanIds(), $dto->companyId, $dto->resultDate);

                $entity = $this->protocolRepository->findById($protocolId, $dto->companyId);

                if ($entity === null) {
                    throw VeterinaryDomainException::protocolNotFound($protocolId);
                }

                return $entity;
            });
        } catch (Throwable $exception) {
            // Compensating action: the transaction is gone, the files are not.
            foreach ($writtenPaths as $path) {
                $this->storage->delete($path);
            }

            throw $exception;
        }
    }

    private function assertPayloadIsCoherent(CreateDiagnosticProtocolDTO $dto, DiagnosticSourceChannel $sourceChannel): void
    {
        if ($dto->protocolNumber === '') {
            throw VeterinaryDomainException::domainError('El número de protocolo es obligatorio.');
        }

        if ($dto->samples === []) {
            throw VeterinaryDomainException::domainError('El protocolo debe contener al menos una determinación.');
        }

        if ($sourceChannel->requiresAttachment() && $dto->attachments === []) {
            throw VeterinaryDomainException::attachmentRequired();
        }

        $maxPerProtocol = (int) config('livestock.attachments.max_per_protocol', 10);

        if (count($dto->attachments) > $maxPerProtocol) {
            throw VeterinaryDomainException::domainError(
                "Se admiten hasta {$maxPerProtocol} archivos de evidencia por protocolo."
            );
        }

        if (Carbon::parse($dto->resultDate)->lt(Carbon::parse($dto->sampleDate))) {
            throw VeterinaryDomainException::domainError(
                'La fecha de resultado no puede ser anterior a la fecha de toma de muestra.'
            );
        }

        // F9: the same protocol cannot carry the same determination twice.
        $seen = [];

        foreach ($dto->samples as $line) {
            $key = sprintf('%d|%d|%d', $line->caravanId, $line->pathogenId, $line->sampleRound);

            if (isset($seen[$key])) {
                throw VeterinaryDomainException::domainError(
                    'El protocolo repite la misma determinación (caravana, patógeno y ronda) más de una vez.'
                );
            }

            $seen[$key] = true;
        }
    }

    private function assertProtocolNumberIsFree(CreateDiagnosticProtocolDTO $dto): void
    {
        $existing = $this->protocolRepository->findByProtocolNumber($dto->protocolNumber, $dto->companyId);

        if ($existing !== null) {
            throw VeterinaryDomainException::duplicateProtocolNumber($dto->protocolNumber, $existing->getId());
        }
    }

    /**
     * F9: only male caravans belonging to the tenant may receive a bull determination.
     */
    private function assertCaravansAreEligible(CreateDiagnosticProtocolDTO $dto): void
    {
        $caravanIds = $dto->caravanIds();

        $eligible = Caravan::query()
            ->where('company_id', $dto->companyId)
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
     * ADR-8: freeze the professional's name and license at signing time so later catalogue
     * edits never rewrite historical protocols.
     *
     * @return array{signed_at: ?string, signed_by_veterinarian_id: ?int, signed_license_number: ?string, signed_veterinarian_name: ?string}
     */
    private function resolveSignature(CreateDiagnosticProtocolDTO $dto): array
    {
        if ($dto->veterinarianId === null) {
            return [
                'signed_at' => null,
                'signed_by_veterinarian_id' => null,
                'signed_license_number' => null,
                'signed_veterinarian_name' => null,
            ];
        }

        $veterinarian = $this->veterinarianRepository->findById($dto->veterinarianId, $dto->companyId);

        if ($veterinarian === null) {
            throw VeterinaryDomainException::veterinarianNotFound($dto->veterinarianId);
        }

        $snapshot = $veterinarian->toSignatureSnapshot();

        return [
            'signed_at' => Carbon::now()->toDateTimeString(),
            'signed_by_veterinarian_id' => $veterinarian->getId(),
            'signed_license_number' => $snapshot['license_number'],
            'signed_veterinarian_name' => $snapshot['name'],
        ];
    }

    /**
     * @return list<string> Paths written, for the compensating deletion.
     */
    private function persistAttachments(CreateDiagnosticProtocolDTO $dto, int $protocolId): array
    {
        $writtenPaths = [];
        $rows = [];

        /** @var ProtocolAttachmentUploadDTO $attachment */
        foreach ($dto->attachments as $attachment) {
            $path = $this->storage->store($dto->companyId, $protocolId, $attachment->contents, $attachment->extension);
            $writtenPaths[] = $path;

            $rows[] = [
                'company_id' => $dto->companyId,
                'diagnostic_protocol_id' => $protocolId,
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
     * ADR-1: every determination is stored in `bull_lab_samples`. Only a POSITIVE_DETECTED
     * result derives a `veterinary_diagnoses` row; a negative never does.
     */
    private function persistSamplesAndFindings(CreateDiagnosticProtocolDTO $dto, int $protocolId): void
    {
        $now = Carbon::now();
        $sampleRows = [];

        /** @var ProtocolSampleLineDTO $line */
        foreach ($dto->samples as $line) {
            $sampleRows[] = [
                'company_id' => $dto->companyId,
                'caravan_id' => $line->caravanId,
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

        DB::table('bull_lab_samples')->insert($sampleRows);

        $positives = array_values(array_filter(
            $dto->samples,
            static fn (ProtocolSampleLineDTO $line): bool => $line->isPositive()
        ));

        if ($positives === []) {
            return;
        }

        // Re-read the inserted positives to link each finding back to its source sample.
        $sampleIds = DB::table('bull_lab_samples')
            ->where('diagnostic_protocol_id', $protocolId)
            ->where('status', LabSampleStatus::POSITIVE_DETECTED->value)
            ->get(['id', 'caravan_id', 'pathogen_id', 'sample_round']);

        $findingRows = [];

        foreach ($sampleIds as $sample) {
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
                'treatment_notes' => 'Hallazgo derivado del protocolo ' . $dto->protocolNumber . '.',
                'source_context' => 'PRE_SERVICE',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('veterinary_diagnoses')->insert($findingRows);
    }
}
