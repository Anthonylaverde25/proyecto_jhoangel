<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Application\DTOs\Veterinary\RegisterSampleShipmentDTO;
use App\Application\Services\PersistProtocolDetailsService;
use App\Core\Entities\SampleShipmentEntity;
use App\Core\Enums\SampleDestinationPlan;
use App\Core\Exceptions\VeterinaryDomainException;
use App\Core\Interfaces\ISampleShipmentRepository;
use App\Core\ValueObjects\InstitutionMeta;
use App\Models\BullLabSample;
use App\Models\ExtractionActDetail;
use App\Models\SampleShipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ADR-30: the professional declares what they dispatched — a fact they witnessed.
 *
 * Nothing here asks about an arrival. The laboratory on the other side does not use the system
 * and never will, and its report is what acknowledges receipt (ADR-32). Asking the person who
 * handed the box over to also certify that it got there is asking them to attest something they
 * did not see, which is the mistake this whole redesign removes.
 */
final class RegisterSampleShipmentUseCase
{
    public function __construct(
        private readonly ISampleShipmentRepository $shipments,
        private readonly PersistProtocolDetailsService $protocolDetails
    ) {
    }

    /**
     * @throws VeterinaryDomainException
     */
    public function __invoke(RegisterSampleShipmentDTO $dto): SampleShipmentEntity
    {
        if ($dto->sampleIds === []) {
            throw VeterinaryDomainException::shipmentNeedsSamples();
        }

        // ADR-29: validated here, so a bad CUIT or a nameless institution never reaches the row.
        $institution = InstitutionMeta::fromArray($dto->institution);

        // §4: a broken cold chain never blocks the record — the professional judges whether the
        // sample is still usable — but the judgement has to be written down or the decision
        // becomes unauditable.
        if (!$dto->coldChainOk
            && (bool) config('livestock.custody.broken_cold_chain_requires_note', true)
            && trim((string) $dto->conditionNotes) === ''
        ) {
            throw VeterinaryDomainException::shipmentRequiresColdChainNote();
        }

        $this->assertTubesAreDispatchable($dto);

        return DB::transaction(function () use ($dto, $institution): SampleShipmentEntity {
            $shipment = SampleShipment::create([
                'company_id' => $dto->companyId,
                'shipped_on' => $dto->shippedOn,
                'institution' => $institution->jsonSerialize(),
                'cold_chain_ok' => $dto->coldChainOk,
                'condition_notes' => $dto->conditionNotes,
                'declared_by_veterinarian_id' => $dto->veterinarianId,
                // Frozen: who said this, at the moment they said it.
                'declared_by_name' => $dto->declaredByName,
                'declared_by_user_id' => $dto->declaredByUserId,
                'declared_at' => Carbon::now(),
            ]);

            BullLabSample::query()
                ->where('company_id', $dto->companyId)
                ->whereIn('id', $dto->sampleIds)
                ->update(['sample_shipment_id' => $shipment->id, 'updated_at' => Carbon::now()]);

            // ADR-39 rev.: el acta que preveía derivar se entera acá de a dónde fue.
            self::declareDestinationOnDerivedActs(
                $this->protocolDetails,
                $dto->companyId,
                $dto->sampleIds,
                $institution
            );

            $entity = $this->shipments->findById((int) $shipment->id, $dto->companyId);

            if ($entity === null) {
                throw VeterinaryDomainException::domainError('No se pudo releer el envío recién registrado.');
            }

            return $entity;
        });
    }

    /**
     * ADR-36 + ADR-39 rev.: a box may carry tubes from several acts, so the destination is
     * recorded on every act that was waiting for one.
     *
     * Only the acts that declared TO_BE_DERIVED. An IN_SITU act whose tubes travelled anyway is
     * left alone: its plan was what it was, the fact of the trip is already on the shipment, and
     * writing a destination onto it would contradict from behind what the professional declared
     * at the chute (ADR-40). An UNDECIDED act is left alone for the same reason — it declared no
     * derivation, and a box does not declare one on its behalf.
     *
     * Last declaration wins. If the next box from the same act goes to a different laboratory,
     * the act shows the latest declared destination, which is the one worth prefilling the next
     * trip with. Nothing is lost: the full history lives in the shipments, one row per box.
     *
     * Static because it also runs from the correction use case (ADR-37), where the same rule has
     * to hold or the act would end up naming a laboratory its own shipment no longer mentions.
     *
     * @param list<int> $sampleIds
     */
    public static function declareDestinationOnDerivedActs(
        PersistProtocolDetailsService $protocolDetails,
        int $companyId,
        array $sampleIds,
        InstitutionMeta $institution
    ): void {
        if ($sampleIds === []) {
            return;
        }

        $actIds = BullLabSample::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $sampleIds)
            ->distinct()
            ->pluck('extraction_act_id');

        $derivedActIds = ExtractionActDetail::query()
            ->whereIn('protocol_id', $actIds)
            ->where('destination_plan', SampleDestinationPlan::TO_BE_DERIVED->value)
            ->pluck('protocol_id');

        foreach ($derivedActIds as $actId) {
            $protocolDetails->declareDestinationInstitution((int) $actId, $institution);
        }
    }

    /**
     * A tube may only travel once, and only if it belongs to a signed act of this professional.
     * Both guards protect the same thing: a box can only carry what its owner actually held.
     *
     * @throws VeterinaryDomainException
     */
    private function assertTubesAreDispatchable(RegisterSampleShipmentDTO $dto): void
    {
        $available = collect($this->shipments->findPendingTubes($dto->veterinarianId, $dto->companyId))
            ->pluck('id')
            ->all();

        foreach ($dto->sampleIds as $sampleId) {
            if (!in_array($sampleId, $available, true)) {
                throw VeterinaryDomainException::sampleNotAvailableForShipment($sampleId);
            }
        }
    }
}
