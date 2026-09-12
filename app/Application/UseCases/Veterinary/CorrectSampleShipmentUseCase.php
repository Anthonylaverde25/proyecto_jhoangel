<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Application\Services\PersistProtocolDetailsService;
use App\Core\Entities\SampleShipmentEntity;
use App\Core\Exceptions\VeterinaryDomainException;
use App\Core\Interfaces\ISampleShipmentRepository;
use App\Core\ValueObjects\InstitutionMeta;
use App\Models\SampleShipment;
use Illuminate\Support\Carbon;

/**
 * ADR-37: a typo the same afternoon does not deserve ceremony.
 *
 * The line is drawn at the moment somebody relied on the document: once a laboratory report has
 * resolved any of its tubes, this shipment is part of a chain that was cited, and correcting it
 * in place would quietly change the meaning of evidence. From there on it is voided and reissued.
 */
final class CorrectSampleShipmentUseCase
{
    public function __construct(
        private readonly ISampleShipmentRepository $shipments,
        private readonly PersistProtocolDetailsService $protocolDetails
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @throws VeterinaryDomainException
     */
    public function __invoke(int $shipmentId, int $companyId, array $data): SampleShipmentEntity
    {
        $shipment = $this->shipments->findById($shipmentId, $companyId);

        if ($shipment === null) {
            throw VeterinaryDomainException::domainError("Envío ID {$shipmentId} no encontrado.");
        }

        if ($shipment->isVoided()) {
            throw VeterinaryDomainException::shipmentAlreadyVoided($shipmentId);
        }

        if ($shipment->isCitedByReport()) {
            throw VeterinaryDomainException::shipmentAlreadyCited($shipmentId);
        }

        $attributes = ['updated_at' => Carbon::now()];
        $institution = null;

        if (isset($data['institution'])) {
            $institution = InstitutionMeta::fromArray((array) $data['institution']);
            $attributes['institution'] = $institution->jsonSerialize();
        }

        if (isset($data['shipped_on'])) {
            $attributes['shipped_on'] = (string) $data['shipped_on'];
        }

        if (array_key_exists('cold_chain_ok', $data)) {
            $attributes['cold_chain_ok'] = (bool) $data['cold_chain_ok'];
        }

        if (array_key_exists('condition_notes', $data)) {
            $attributes['condition_notes'] = $data['condition_notes'];
        }

        $coldChainOk = $attributes['cold_chain_ok'] ?? $shipment->isColdChainOk();
        $notes = $attributes['condition_notes'] ?? $shipment->getConditionNotes();

        if (!$coldChainOk
            && (bool) config('livestock.custody.broken_cold_chain_requires_note', true)
            && trim((string) $notes) === ''
        ) {
            throw VeterinaryDomainException::shipmentRequiresColdChainNote();
        }

        SampleShipment::query()->where('id', $shipmentId)->where('company_id', $companyId)->update($attributes);

        // ADR-39 rev.: si cambió el destinatario, el acta tiene que cambiar con él. Si no, el acta
        // queda nombrando un laboratorio que su propio envío ya no menciona — el desacuerdo
        // callado que esta columna existe para evitar.
        if ($institution !== null) {
            RegisterSampleShipmentUseCase::declareDestinationOnDerivedActs(
                $this->protocolDetails,
                $companyId,
                array_map(static fn (array $sample): int => (int) $sample['id'], $shipment->getSamples()),
                $institution
            );
        }

        $updated = $this->shipments->findById($shipmentId, $companyId);

        if ($updated === null) {
            throw VeterinaryDomainException::domainError('No se pudo releer el envío corregido.');
        }

        return $updated;
    }
}
