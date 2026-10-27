<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Enums\BullReplacementReason;
use App\Core\Enums\ServiceOrderMaleStatus;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderMale;

/**
 * Taking an active bull out of a running service order, shared by the replacement (another
 * bull comes in) and the plain retirement (no spare bull, e.g. a venereal positive).
 */
final class ServiceBullRetirement
{
    /**
     * @return array{0: ServiceOrder, 1: Caravan, 2: ServiceOrderMale}
     *
     * @throws ServiceOrderDomainException
     */
    public function loadActiveBull(int $serviceOrderId, int $bullId, int $companyId): array
    {
        $serviceOrder = ServiceOrder::withoutGlobalScopes()
            ->where('id', $serviceOrderId)
            ->where('company_id', $companyId)
            ->first();

        if ($serviceOrder === null) {
            throw ServiceOrderDomainException::domainError("La orden de servicio con ID {$serviceOrderId} no existe.");
        }

        if ($serviceOrder->status !== ServiceOrderStatus::APPROVED->value) {
            throw ServiceOrderDomainException::domainError(
                "Solo se pueden retirar o sustituir reproductores en órdenes de servicio aprobadas o en curso (Estado actual: {$serviceOrder->status})."
            );
        }

        $bull = Caravan::withoutGlobalScopes()
            ->where('id', $bullId)
            ->where('company_id', $companyId)
            ->first();

        if ($bull === null) {
            throw ServiceOrderDomainException::domainError("El reproductor saliente con ID {$bullId} no fue encontrado.");
        }

        $serviceMale = ServiceOrderMale::withoutGlobalScopes()
            ->where('service_order_id', $serviceOrder->id)
            ->where('male_caravan_id', $bullId)
            ->first();

        if ($serviceMale === null) {
            throw ServiceOrderDomainException::domainError(
                "El reproductor #{$bull->identification} no está asignado a la orden de servicio {$serviceOrder->code}."
            );
        }

        if ($serviceMale->status !== ServiceOrderMaleStatus::ACTIVE->value) {
            throw ServiceOrderDomainException::domainError(
                "El reproductor #{$bull->identification} ya no se encuentra activo en esta orden de servicio (Estado: {$serviceMale->status})."
            );
        }

        return [$serviceOrder, $bull, $serviceMale];
    }

    /**
     * Where the bull goes: the requested batch, or back to the batch he came from (nowhere
     * when he died).
     *
     * @throws ServiceOrderDomainException
     */
    public function resolveDestination(
        ServiceOrder $serviceOrder,
        Caravan $bull,
        BullReplacementReason $reason,
        ?int $destinationBatchId,
        int $companyId
    ): ?int {
        if ($destinationBatchId === null && $reason !== BullReplacementReason::DEATH) {
            $lastEntryMovement = CaravanMovement::where('caravan_id', $bull->id)
                ->where('company_id', $companyId)
                ->where('to_batch_id', (int) $serviceOrder->batch_id)
                ->latest('movement_date')
                ->first();

            $destinationBatchId = $lastEntryMovement?->from_batch_id;
        }

        if ($destinationBatchId !== null) {
            $exists = Batch::withoutGlobalScopes()
                ->where('id', $destinationBatchId)
                ->where('company_id', $companyId)
                ->exists();
            if (!$exists) {
                throw ServiceOrderDomainException::domainError("El lote de destino con ID {$destinationBatchId} no existe en esta empresa.");
            }
        }

        return $destinationBatchId;
    }

    /**
     * Close the bull's stay in the order and move him out of the serviced batch.
     */
    public function retire(
        ServiceOrder $serviceOrder,
        Caravan $bull,
        ServiceOrderMale $serviceMale,
        BullReplacementReason $reason,
        string $date,
        ?int $destinationBatchId,
        ?string $notes,
        int $companyId
    ): void {
        $serviceMale->update([
            'status'                    => ServiceOrderMaleStatus::forRetirement($reason)->value,
            'retired_at'                => $date,
            'exit_reason'               => $reason->value,
            'exit_notes'                => $notes,
            'exit_destination_batch_id' => $reason === BullReplacementReason::DEATH ? null : $destinationBatchId,
        ]);

        $previousBatchId = $bull->batch_id;

        if ($reason === BullReplacementReason::DEATH) {
            $bull->batch_id = null;
            $bull->save();

            CaravanMovement::create([
                'company_id'    => $companyId,
                'caravan_id'    => $bull->id,
                'renspa'        => $bull->renspa ?: 'NO_DEFINIDO',
                'type'          => 'TRANSFER',
                'movement_date' => $date,
                'from_batch_id' => $previousBatchId,
                'to_batch_id'   => null,
                'observations'  => "Baja por muerte en potrero durante orden {$serviceOrder->code}",
            ]);

            return;
        }

        if ($destinationBatchId !== null) {
            $bull->batch_id = $destinationBatchId;
            $bull->save();

            CaravanMovement::create([
                'company_id'    => $companyId,
                'caravan_id'    => $bull->id,
                'renspa'        => $bull->renspa ?: 'NO_DEFINIDO',
                'type'          => 'TRANSFER',
                'movement_date' => $date,
                'from_batch_id' => $previousBatchId,
                'to_batch_id'   => $destinationBatchId,
                'observations'  => "Retiro de servicio {$serviceOrder->code} por {$reason->label()}" .
                    ($notes ? ": {$notes}" : ''),
            ]);
        }
    }
}
