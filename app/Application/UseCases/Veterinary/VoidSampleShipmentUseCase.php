<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Core\Entities\SampleShipmentEntity;
use App\Core\Exceptions\VeterinaryDomainException;
use App\Core\Interfaces\ISampleShipmentRepository;
use App\Models\BullLabSample;
use App\Models\SampleShipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ADR-37: nothing is erased. The row stays, marked void with a reason, and the tubes it carried
 * go back to being in the professional's hands so a corrected shipment can be issued.
 */
final class VoidSampleShipmentUseCase
{
    public function __construct(private readonly ISampleShipmentRepository $shipments)
    {
    }

    /**
     * @throws VeterinaryDomainException
     */
    public function __invoke(int $shipmentId, int $companyId, string $reason, ?int $userId): SampleShipmentEntity
    {
        if (trim($reason) === '') {
            throw VeterinaryDomainException::domainError('Anular un envío exige un motivo: es lo que se lee después.');
        }

        $shipment = $this->shipments->findById($shipmentId, $companyId);

        if ($shipment === null) {
            throw VeterinaryDomainException::domainError("Envío ID {$shipmentId} no encontrado.");
        }

        if ($shipment->isVoided()) {
            throw VeterinaryDomainException::shipmentAlreadyVoided($shipmentId);
        }

        return DB::transaction(function () use ($shipmentId, $companyId, $reason, $userId): SampleShipmentEntity {
            SampleShipment::query()
                ->where('id', $shipmentId)
                ->where('company_id', $companyId)
                ->update([
                    'voided_at' => Carbon::now(),
                    'void_reason' => trim($reason),
                    'voided_by_user_id' => $userId,
                    'updated_at' => Carbon::now(),
                ]);

            // The tubes were never where this document said: they go back to the professional,
            // available for a corrected shipment. Only the ones nobody reported on — a resolved
            // tube belongs to a report, and voiding a box never rewrites a result.
            BullLabSample::query()
                ->where('company_id', $companyId)
                ->where('sample_shipment_id', $shipmentId)
                ->whereNull('diagnostic_protocol_id')
                ->update(['sample_shipment_id' => null, 'updated_at' => Carbon::now()]);

            $voided = $this->shipments->findById($shipmentId, $companyId);

            if ($voided === null) {
                throw VeterinaryDomainException::domainError('No se pudo releer el envío anulado.');
            }

            return $voided;
        });
    }
}
