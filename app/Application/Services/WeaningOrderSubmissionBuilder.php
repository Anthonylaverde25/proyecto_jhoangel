<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\Dest01\Dest01SubmissionDTO;
use App\Application\DTOs\WeaningOrders\WeaningFieldData;
use App\Core\Entities\WeaningOrderEntity;
use App\Core\Enums\TransferOrderCategoryMode;
use App\Core\Enums\WeaningType;
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
     * @param list<int>|null $onlyCaravanIds wean only these pending calves; null = all of them
     * @param WeaningType|null $weaningType how they were weaned, when it was declared at weaning
     *
     * @throws WeaningOrderDomainException
     */
    public function fromOrder(
        WeaningOrderEntity $order,
        string $weaningDate,
        string $origin,
        ?int $userId,
        array $fieldDataByCaravanId = [],
        ?array $onlyCaravanIds = null,
        ?WeaningType $weaningType = null
    ): Dest01SubmissionDTO {
        $pending = $order->pendingAnimals();

        if ($pending === []) {
            throw WeaningOrderDomainException::domainError("La orden {$order->getCode()} no tiene crías pendientes.", 'NOTHING_PENDING');
        }

        // Some of the pending calves only: the others stay pending and the order is left PARTIAL.
        if ($onlyCaravanIds !== null) {
            $wanted = array_flip($onlyCaravanIds);
            $pendingIds = array_flip(array_map(fn ($line) => $line->getCaravanId(), $pending));
            $foreign = array_diff_key($wanted, $pendingIds);

            if ($foreign !== []) {
                throw WeaningOrderDomainException::domainError(
                    count($foreign) . " cría(s) elegida(s) no están pendientes en la orden {$order->getCode()}.",
                    'NOT_PENDING_IN_ORDER'
                );
            }

            $pending = array_values(array_filter($pending, fn ($line) => isset($wanted[$line->getCaravanId()])));
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

            if ($destination->staysInPlace()) {
                $destinations[] = ['key' => $destination->getKey(), 'target_batch_id' => null, 'new_batch' => null, 'stays_in_place' => true];
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
            // The type declared at weaning, when it was declared; else the one the order planned.
            tipoDestete: ($weaningType ?? $order->getWeaningType())?->value,
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
