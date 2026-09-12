<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Application\DTOs\Veterinary\SignExtractionActDTO;
use App\Application\Services\RecomputeBullAptitudeBulkService;
use App\Core\Entities\DiagnosticProtocolEntity;
use App\Core\Enums\DiagnosticProtocolType;
use App\Core\Enums\DiagnosticSourceChannel;
use App\Core\Enums\ProtocolStatus;
use App\Core\Enums\ProtocolVerificationStatus;
use App\Core\Exceptions\VeterinaryDomainException;
use App\Core\Interfaces\IDiagnosticProtocolRepository;
use App\Core\Interfaces\IVeterinarianRepository;
use App\Models\BullLabSample;
use App\Application\Services\PersistProtocolDetailsService;
use App\Core\Enums\SampleDestinationPlan;
use App\Core\ValueObjects\InstitutionMeta;
use App\Models\DiagnosticProtocol;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one and only place where a sanitary document gets signed.
 *
 * ADR-13: a signature is a professional act. The producer's chute screen prepares the act; the
 * acting veterinarian closes it — in the chute, on their phone, minutes later. From this moment
 * the act is immutable: correcting it means voiding and reissuing (ADR-9), which is precisely
 * what stops a tube from being reassigned to a different bull after the fact.
 *
 * ADR-15: the institution is frozen alongside the professional. Renaming a laboratory afterwards
 * must not move historical evidence to a different one.
 */
final class SignExtractionActUseCase
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
    public function __invoke(SignExtractionActDTO $dto): DiagnosticProtocolEntity
    {
        $act = $this->protocolRepository->findById($dto->actId, $dto->companyId);

        if ($act === null) {
            throw VeterinaryDomainException::actNotFound($dto->actId);
        }

        if (!$act->isExtractionAct()) {
            throw VeterinaryDomainException::protocolTypeMismatch(
                DiagnosticProtocolType::EXTRACTION_ACT->value,
                $act->getProtocolType()->value
            );
        }

        // Only the professional the act was issued to may sign it. Checked against the resolved
        // portal session, never against a client supplied id.
        if ($act->getVeterinarianId() !== $dto->veterinarianId) {
            throw VeterinaryDomainException::signatureNotAllowed($act->getProtocolNumber());
        }

        if ($act->isSigned() || $act->getStatus() === ProtocolStatus::CONFIRMED) {
            throw VeterinaryDomainException::actAlreadySigned($act->getProtocolNumber());
        }

        if (!$act->canBeSigned()) {
            throw VeterinaryDomainException::domainError(
                "El acta {$act->getProtocolNumber()} no está en condiciones de ser firmada (estado {$act->getStatus()->value})."
            );
        }

        $veterinarian = $this->veterinarianRepository->findById($dto->veterinarianId, $dto->companyId);

        if ($veterinarian === null) {
            throw VeterinaryDomainException::veterinarianNotFound($dto->veterinarianId);
        }

        // ADR-29 / ADR-38: a signature attests a PERSON — name, licence and both CUITs. It
        // carries no institution at all: freezing one next to it is exactly what let a third
        // party laboratory end up stamped under somebody else's licence.
        $snapshot = $veterinarian->toSignatureSnapshot();

        return DB::transaction(function () use ($dto, $act, $snapshot): DiagnosticProtocolEntity {
            $affected = DiagnosticProtocol::query()
                ->where('id', $dto->actId)
                ->where('company_id', $dto->companyId)
                // Guards against a double submit racing itself: the second update matches nothing.
                ->where('status', ProtocolStatus::DRAFT->value)
                ->whereNull('signed_at')
                ->update([
                    'status' => ProtocolStatus::CONFIRMED->value,
                    'verification_status' => ProtocolVerificationStatus::VERIFIED->value,
                    // The professional attested to this document, so the channel is no longer a
                    // producer transcription.
                    'source_channel' => DiagnosticSourceChannel::PORTAL_VET->value,
                    'signed_at' => Carbon::now(),
                    'signed_by_veterinarian_id' => $dto->veterinarianId,
                    'signed_license_number' => $snapshot['license_number'],
                    'signed_veterinarian_name' => $snapshot['name'],
                    'signed_cuit' => $snapshot['cuit'],
                    'signed_billing_cuit' => $snapshot['billing_cuit'],
                    'observations' => $dto->observations ?? $act->getObservations(),
                    'updated_by_user_id' => $dto->signedByUserId,
                    'updated_at' => Carbon::now(),
                ]);

            if ($affected === 0) {
                throw VeterinaryDomainException::actAlreadySigned($act->getProtocolNumber());
            }

            // ADR-39: last chance to correct what was declared at the chute. After this the act is
            // CONFIRMED, and nothing that it attests to changes again.
            $this->protocolDetails->upsertActDetail(
                protocolId: $dto->actId,
                institution: InstitutionMeta::fromNullableArray($dto->institution) ?? $act->getActInstitution(),
                destinationPlan: $dto->destinationPlan !== null
                    ? SampleDestinationPlan::fromNullable($dto->destinationPlan)
                    : $act->getDestinationPlan(),
                dispatchNoteNumber: $dto->dispatchNoteNumber ?? $act->getDispatchNoteNumber(),
                dispatchedAt: $dto->dispatchedAt ?? $act->getDispatchedAt()?->format('Y-m-d')
            );

            // The tubes only start counting once the act behind them is signed (ADR-17), so the
            // aptitude of every bull in the act has to be recomputed now.
            $caravanIds = $this->caravanIdsOfAct($dto->actId, $dto->companyId);

            if ($caravanIds !== []) {
                $this->recomputeAptitude->__invoke($caravanIds, $dto->companyId, $act->getSampleDate()->format('Y-m-d'));
            }

            $signed = $this->protocolRepository->findById($dto->actId, $dto->companyId);

            if ($signed === null) {
                throw VeterinaryDomainException::actNotFound($dto->actId);
            }

            return $signed;
        });
    }


    /**
     * @return list<int>
     */
    private function caravanIdsOfAct(int $actId, int $companyId): array
    {
        return BullLabSample::query()
            ->where('company_id', $companyId)
            ->where('extraction_act_id', $actId)
            ->pluck('caravan_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
