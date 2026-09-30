<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\Dest01\Dest01SubmissionDTO;
use App\Application\DTOs\WeaningOrders\WeaningFieldData;
use App\Core\Entities\WeaningOrderEntity;
use App\Core\Enums\TransferOrderCategoryMode;
use App\Core\Exceptions\WeaningOrderDomainException;

/**
 * Turns a weaning order into the DEST-01 submission that executes it.
 *
 * The submission is built from the ORDER, not from whatever a screen shows, so what gets weaned is
 * exactly what was committed. Executing asks more than ordering: every pending calf needs its
 * weaning batch, every batch to be created needs its management system, and an order that left the
 * category for the chute needs somebody to decide it for every calf.
 */
final class WeaningOrderSubmissionBuilder
{
    /**
     * @param string $weaningDate when the weaning happened (Y-m-d), which may differ from the date
     *                            the order was planned for
     * @param array<int, WeaningFieldData> $fieldDataByCaravanId what was measured or decided per calf
     *
     * @throws WeaningOrderDomainException
     */
    public function fromOrder(
        WeaningOrderEntity $order,
        string $weaningDate,
        string $origin,
        ?int $userId,
        array $fieldDataByCaravanId = []
    ): Dest01SubmissionDTO {
        $pending = $order->pendingAnimals();

        if ($pending === []) {
            throw WeaningOrderDomainException::domainError("La orden {$order->getCode()} no tiene crías pendientes.", 'NOTHING_PENDING');
        }

        $unassigned = array_filter($pending, fn ($line) => $line->getDestinationKey() === null);

        if ($unassigned !== []) {
            throw WeaningOrderDomainException::domainError(
                count($unassigned) . ' cría(s) de la orden no tienen lote de destete: se deciden en la manga. Ejecutala escaneando la planilla.',
                'DESTINATION_MISSING'
            );
        }

        // "Se decide en la manga", executed without the chute: somebody still has to decide it.
        // A calf sent with no category is a decision too — it keeps the one it has.
        if ($order->getCategoryMode() === TransferOrderCategoryMode::AT_CHUTE) {
            $undecided = array_filter($pending, fn ($line) => !isset($fieldDataByCaravanId[$line->getCaravanId()]));

            if ($undecided !== []) {
                throw WeaningOrderDomainException::domainError(
                    'La orden deja la categoría para la manga: indicá la categoría nueva (o "sin cambio") de cada cría, o ejecutala escaneando la planilla.',
                    'CATEGORY_DECISION_MISSING'
                );
            }
        }

        $usedKeys = array_flip(array_map(fn ($line) => (string) $line->getDestinationKey(), $pending));
        $destinations = [];

        foreach ($order->getDestinations() as $destination) {
            if (!isset($usedKeys[$destination->getKey()])) {
                continue;
            }

            $batchId = $destination->getResolvedBatchId() ?? $destination->getTargetBatchId();

            if ($batchId === null && $destination->isConfined() === null) {
                throw WeaningOrderDomainException::domainError(
                    "El lote de destete nuevo '{$destination->getLabel()}' no tiene declarado el sistema de manejo. Completalo al escanear la planilla.",
                    'NEW_BATCH_INCOMPLETE'
                );
            }

            $destinations[] = [
                'key' => $destination->getKey(),
                'target_batch_id' => $batchId,
                'new_batch' => $batchId === null ? [
                    'name' => (string) $destination->getNewBatchName(),
                    'is_confined' => $destination->isConfined(),
                ] : null,
            ];
        }

        $rows = array_map(function ($line) use ($fieldDataByCaravanId) {
            $field = $fieldDataByCaravanId[$line->getCaravanId()] ?? null;

            return [
                'caravana' => (string) $line->getIdentification(),
                'caravana_madre' => $line->getMotherIdentification(),
                'peso' => $field?->weightKg,
                'observations' => $field?->observations,
                'destination_key' => (string) $line->getDestinationKey(),
                'manejo' => null,
                'cs_nueva' => null,
                'category_id' => $field?->categoryId,
                'subcategory_id' => $field?->subcategoryId,
            ];
        }, $pending);

        return new Dest01SubmissionDTO(
            companyId: $order->getCompanyId(),
            fechaDestete: $weaningDate,
            tipoDestete: $order->getWeaningType()?->value,
            loteOrigen: null,
            responsable: $order->getResponsable(),
            observaciones: $order->getObservations(),
            rows: array_values($rows),
            destinationMode: $order->getDestinationMode(),
            destinations: $destinations,
            origin: $origin,
            weaningOrderId: $order->getId(),
            actionUserId: $userId
        );
    }
}
