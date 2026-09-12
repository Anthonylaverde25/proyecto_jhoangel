<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Application\DTOs\Veterinary\VoidDiagnosticProtocolDTO;
use App\Application\Services\RecomputeBullAptitudeBulkService;
use App\Core\Entities\DiagnosticProtocolEntity;
use App\Core\Enums\DiagnosisStatus;
use App\Core\Enums\LabSampleStatus;
use App\Core\Enums\ProtocolStatus;
use App\Core\Exceptions\VeterinaryDomainException;
use App\Core\Interfaces\IDiagnosticProtocolRepository;
use App\Models\DiagnosticProtocol;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Use Case 3 — Correction and voiding (ADR-9).
 *
 * Without this flow a typing error over a phone photo is permanent, and can enable or block
 * a bull for the wrong reason. Voiding reverts the derived findings and recomputes aptitude
 * for every caravan the protocol touched.
 */
final class VoidDiagnosticProtocolUseCase
{
    public function __construct(
        private readonly IDiagnosticProtocolRepository $protocolRepository,
        private readonly RecomputeBullAptitudeBulkService $recomputeAptitude
    ) {
    }

    /**
     * @throws VeterinaryDomainException
     */
    public function __invoke(VoidDiagnosticProtocolDTO $dto): DiagnosticProtocolEntity
    {
        if ($dto->reason === '') {
            throw VeterinaryDomainException::domainError('La anulación exige un motivo explícito.');
        }

        $protocol = $this->protocolRepository->findById($dto->protocolId, $dto->companyId);

        if ($protocol === null) {
            throw VeterinaryDomainException::protocolNotFound($dto->protocolId);
        }

        if ($protocol->isVoided()) {
            throw VeterinaryDomainException::protocolAlreadyVoided($protocol->getProtocolNumber());
        }

        return DB::transaction(function () use ($dto, $protocol): DiagnosticProtocolEntity {
            // ADR-9 + ADR-11: voiding an extraction act must take its laboratory report with it.
            // A report that outlived its act would keep certifying tubes whose chain of custody
            // has just been declared invalid.
            $affectedProtocolIds = [$dto->protocolId];

            if ($protocol->isExtractionAct()) {
                $childIds = DiagnosticProtocol::query()
                    ->where('company_id', $dto->companyId)
                    ->where('parent_protocol_id', $dto->protocolId)
                    ->where('status', '!=', ProtocolStatus::VOIDED->value)
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();

                $affectedProtocolIds = array_values(array_unique(array_merge($affectedProtocolIds, $childIds)));
            }

            $caravanIds = DB::table('bull_lab_samples')
                ->where('company_id', $dto->companyId)
                ->where(function ($query) use ($dto, $affectedProtocolIds): void {
                    $query->whereIn('diagnostic_protocol_id', $affectedProtocolIds)
                        ->orWhere('extraction_act_id', $dto->protocolId);
                })
                ->pluck('caravan_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            // Derived findings are resolved rather than deleted: the clinical history must keep
            // showing that something was once recorded, and why it stopped counting.
            $derivedFindings = DB::table('veterinary_diagnoses')
                ->whereIn('diagnostic_protocol_id', $affectedProtocolIds)
                ->where('company_id', $dto->companyId)
                ->get(['id', 'treatment_notes']);

            $voidNote = sprintf(
                '[ANULADO %s] Protocolo %s anulado: %s',
                Carbon::now()->toDateString(),
                $protocol->getProtocolNumber(),
                $dto->reason
            );

            foreach ($derivedFindings as $finding) {
                DB::table('veterinary_diagnoses')
                    ->where('id', (int) $finding->id)
                    ->update([
                        'status' => DiagnosisStatus::RESOLVED->value,
                        'resolution_date' => Carbon::now()->toDateString(),
                        'treatment_notes' => trim((string) ($finding->treatment_notes ?? '') . ' ' . $voidNote),
                        'updated_at' => Carbon::now(),
                    ]);
            }

            DiagnosticProtocol::query()
                ->whereIn('id', $affectedProtocolIds)
                ->where('company_id', $dto->companyId)
                ->update([
                    'status' => ProtocolStatus::VOIDED->value,
                    'voided_at' => Carbon::now(),
                    'voided_by_user_id' => $dto->voidedByUserId,
                    'void_reason' => $dto->reason,
                    'updated_by_user_id' => $dto->voidedByUserId,
                    'updated_at' => Carbon::now(),
                ]);

            if ($protocol->isExtractionAct()) {
                // The tubes go back to awaiting a laboratory that will now never report on this
                // act, which is exactly the state a reissued act needs to find them in.
                DB::table('bull_lab_samples')
                    ->where('company_id', $dto->companyId)
                    ->where('extraction_act_id', $dto->protocolId)
                    ->update([
                        'diagnostic_protocol_id' => null,
                        'protocol_number' => null,
                        'status' => LabSampleStatus::PENDING_RESULTS->value,
                        'result_date' => null,
                        'updated_at' => Carbon::now(),
                    ]);
            }

            // The samples stay in place but stop counting: findVenerealSampling only reads
            // determinations whose extraction act — or laboratory report — is CONFIRMED (ADR-17).
            $this->recomputeAptitude->__invoke($caravanIds, $dto->companyId);

            $entity = $this->protocolRepository->findById($dto->protocolId, $dto->companyId);

            return $entity ?? $protocol;
        });
    }
}
