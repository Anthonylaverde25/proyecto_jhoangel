<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\Cact01\Cact01SubmissionDTO;
use App\Application\DTOs\TransferOrders\RegisterFieldData;
use App\Core\Entities\TransferOrderEntity;
use App\Core\Exceptions\TransferOrderDomainException;

/**
 * Turns an order into the CACT-01 submission that executes it.
 *
 * The submission is built from the ORDER, not from whatever a screen shows, so what gets moved
 * is exactly what was committed. Executing asks more than ordering: every pending animal needs a
 * batch, and every batch to be created needs its type and management system.
 */
final class TransferOrderSubmissionBuilder
{
    /**
     * @param string $movementDate when the movement happened (Y-m-d), which may differ from the
     *                             date the order was planned for
     *
     * @param array<int, RegisterFieldData> $fieldDataByCaravanId what was measured at the chute,
     *                                                         by caravan; empty when executing an order
     *
     * @throws TransferOrderDomainException
     */
    public function fromOrder(
        TransferOrderEntity $order,
        string $movementDate,
        string $origin,
        ?int $userId,
        array $fieldDataByCaravanId = []
    ): Cact01SubmissionDTO
    {
        $pending = $order->pendingAnimals();
        $unassigned = array_filter($pending, fn ($line) => $line->getDestinationKey() === null);

        if ($unassigned !== []) {
            throw TransferOrderDomainException::domainError(
                count($unassigned) . ' animal(es) de la orden no tienen lote asignado: se deciden en la manga. Ejecutala escaneando la planilla.',
                'DESTINATION_MISSING'
            );
        }

        $usedKeys = array_flip(array_map(fn ($line) => (string) $line->getDestinationKey(), $pending));
        $destinations = [];

        foreach ($order->getDestinations() as $destination) {
            if (!isset($usedKeys[$destination->getKey()])) {
                continue;
            }

            $batchId = $destination->getResolvedBatchId() ?? $destination->getTargetBatchId();

            if ($batchId === null && ($destination->getNewBatchTypeId() === null || $destination->isConfined() === null)) {
                throw TransferOrderDomainException::domainError(
                    "El lote nuevo '{$destination->getLabel()}' no tiene declarado el tipo o el manejo. Completalo al escanear la planilla.",
                    'NEW_BATCH_INCOMPLETE'
                );
            }

            $destinations[] = [
                'key' => $destination->getKey(),
                'target_batch_id' => $batchId,
                'new_batch' => $batchId === null ? [
                    'name' => (string) $destination->getNewBatchName(),
                    'activity_id' => $order->getDestinationActivityId(),
                    'batch_type_id' => (int) $destination->getNewBatchTypeId(),
                    'is_confined' => $destination->isConfined(),
                ] : null,
            ];
        }

        $rows = array_map(function ($line) use ($fieldDataByCaravanId) {
            $field = $fieldDataByCaravanId[$line->getCaravanId()] ?? null;

            return [
                'caravana' => (string) $line->getIdentification(),
                'peso_actual' => $field?->weightKg,
                'categoria' => null,
                'dientes' => $field?->teeth,
                'destination_key' => (string) $line->getDestinationKey(),
                'manejo' => null,
                'observations' => $field?->observations,
                'category_id' => $field?->categoryId,
                'subcategory_id' => $field?->subcategoryId,
            ];
        }, $pending);

        return new Cact01SubmissionDTO(
            companyId: $order->getCompanyId(),
            sourceBatchId: $order->getSourceBatchId(),
            fechaMovimiento: $movementDate,
            actividadOrigen: null,
            actividadDestinoId: $order->getDestinationActivityId(),
            actividadDestino: null,
            sistemaManejo: null,
            totalCabezas: null,
            pesoTotal: null,
            responsable: $order->getResponsable(),
            observaciones: "Orden de transferencia {$order->getCode()}",
            destinations: $destinations,
            rows: array_values($rows),
            origin: $origin,
            transferOrderId: $order->getId(),
            actionUserId: $userId
        );
    }
}
