<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\Cact01\Cact01SubmissionDTO;
use App\Application\DTOs\TransferOrders\EmitTransferOrderDTO;
use App\Core\Entities\BatchEntity;
use App\Core\Entities\CaravanEntity;
use App\Core\Entities\TransferOrderEntity;
use App\Core\Enums\TransferOrderAnimalStatus;
use App\Core\Enums\TransferOrderCategoryMode;
use App\Core\Enums\TransferOrderKind;
use App\Core\Exceptions\TransferOrderDomainException;
use App\Core\Interfaces\ITransferOrderRepository;
use App\Core\Services\AnimalCategoryTextResolver;

/**
 * What a transfer order adds to a CACT-01 execution, kept out of the use case that already
 * carries the whole sheet.
 *
 * It is called at two moments. BEFORE moving, to check the order can be executed against and to
 * let a second round reuse the batches the first one created. AFTER moving, inside the same
 * transaction, to mark each line of the roll with the movement that fulfilled it. If the order
 * cannot record its execution the movement is rolled back as well: a movement whose order was
 * left behind is worse than a movement that did not happen.
 */
final class TransferOrderExecutionService
{
    public function __construct(
        private readonly ITransferOrderRepository $repository,
        private readonly TransferOrderFactory $factory
    ) {
    }

    /**
     * Loads the order the sheet claims to fulfil, adding a header error for every reason it
     * cannot be executed against.
     *
     * @param array<int, array{field: string, code: string, message: string}> $headerErrors
     */
    public function load(Cact01SubmissionDTO $dto, array &$headerErrors): ?TransferOrderEntity
    {
        if ($dto->transferOrderId === null) {
            // A blank code box means the sheet was printed blank and gets its order on confirming.
            // A code that resolved to nothing is not that: it is a misreading or a paper the system
            // never printed, and creating a second order over it would hide the first one.
            if ($dto->origin === Cact01SubmissionDTO::ORIGIN_SHEET && $dto->paperOrderCode !== null) {
                $headerErrors[] = $this->headerError(
                    'TRANSFER_ORDER_NOT_FOUND',
                    "La planilla trae el código {$dto->paperOrderCode} y no existe ninguna orden con ese código. Corregí la lectura, o borralo si la planilla se llenó sin orden."
                );
            }

            return null;
        }

        $order = $this->repository->findById($dto->transferOrderId, $dto->companyId);

        if ($order === null) {
            $headerErrors[] = $this->headerError('TRANSFER_ORDER_NOT_FOUND', 'La orden de transferencia de la planilla no existe.');

            return null;
        }

        if (!$order->getStatus()->isOpen()) {
            // Replaces the misleading NOT_IN_SOURCE_BATCH a second load used to produce: the
            // animals are not missing, the order was already fulfilled. A draft never went out on
            // paper, so a sheet carrying its code was not printed by the system.
            $headerErrors[] = $this->headerError(
                'TRANSFER_ORDER_NOT_EXECUTABLE',
                $order->getStatus()->isEditable()
                    ? "La orden {$order->getCode()} es un borrador: hay que emitirla antes de ejecutarla."
                    : "La orden {$order->getCode()} está {$order->getStatus()->label()}: no admite más movimientos."
            );
        }

        if ($order->getSourceBatchId() !== $dto->sourceBatchId) {
            $headerErrors[] = $this->headerError(
                'TRANSFER_ORDER_SOURCE_MISMATCH',
                "La orden {$order->getCode()} es del lote '{$order->getSourceBatchName()}', no del lote de origen de la planilla."
            );
        }

        // A planned order cannot be fulfilled before it existed. What happened earlier is loaded with
        // "Registrar transferencia", whose order is born with the movement (REGISTERED).
        $emittedOn = $order->getEmittedAt()?->format('Y-m-d');

        if ($order->getKind() === TransferOrderKind::PLANNED && $emittedOn !== null
            && substr($dto->fechaMovimiento, 0, 10) < $emittedOn) {
            // Marked on the date, not on the order code: the date is what has to be corrected.
            $headerErrors[] = [
                'field' => 'fecha_movimiento',
                'code' => 'MOVEMENT_BEFORE_ORDER',
                'message' => "La orden {$order->getCode()} se emitió el " . date('d/m/Y', (int) strtotime($emittedOn))
                    . '. El movimiento no puede tener una fecha anterior.',
            ];
        }

        if ($order->getDestinationActivityId() !== $dto->actividadDestinoId) {
            $headerErrors[] = $this->headerError(
                'TRANSFER_ORDER_ACTIVITY_MISMATCH',
                "La orden {$order->getCode()} tiene destino {$order->getDestinationActivityName()}, no la actividad de destino de la planilla."
            );
        }

        return $order;
    }

    /**
     * Whether confirming this sheet has to create its order: a scanned sheet with the code box
     * blank was printed blank, filled at the chute and never had one.
     */
    public function needsOrderFromSheet(?TransferOrderEntity $order, Cact01SubmissionDTO $dto): bool
    {
        return $order === null && $dto->origin === Cact01SubmissionDTO::ORIGIN_SHEET && $dto->paperOrderCode === null;
    }

    /**
     * The order of a sheet that arrived without one, created on confirming it and inside the
     * movement's transaction: if the movement fails, the order never existed.
     *
     * It is a REGISTERED order — the movement happened in the field before the system knew of it,
     * exactly as in "Registrar transferencia" — and its roll is the rows of the sheet, so nothing
     * on it can be missing or foreign. It is issued without the commitment check on purpose: a
     * sheet without an order never had that rule, and creating its order must not add one.
     *
     * Called with the animals still in the source batch and the new batches not yet created,
     * which is what the roster checks expect.
     *
     * @param array<string, array{target_batch_id: ?int, new_batch: ?array{name: string, activity_id: int, batch_type_id: int, is_confined: ?bool}}> $destinations validated, by sheet key
     * @param array<int, CaravanEntity> $animalsByRow
     *
     * @throws TransferOrderDomainException
     */
    public function createFromSheet(
        Cact01SubmissionDTO $dto,
        array $destinations,
        array $animalsByRow,
        string $note = 'Orden creada al confirmar una planilla escaneada que no traía orden'
    ): TransferOrderEntity {
        $emitDestinations = [];
        foreach ($destinations as $key => $destination) {
            $newBatch = $destination['new_batch'];

            $emitDestinations[] = [
                'key' => (string) $key,
                'label' => $newBatch['name'] ?? (string) $key,
                'target_batch_id' => $destination['target_batch_id'],
                'new_batch_name' => $newBatch['name'] ?? null,
                'new_batch_type_id' => $newBatch['batch_type_id'] ?? null,
                'is_confined' => $newBatch['is_confined'] ?? null,
            ];
        }

        $animals = [];
        foreach ($animalsByRow as $index => $animal) {
            $animals[] = [
                'caravan_id' => (int) $animal->getId(),
                'destination_key' => $dto->rows[$index]['destination_key'],
            ];
        }

        $order = $this->factory->create(
            new EmitTransferOrderDTO(
                companyId: $dto->companyId,
                requestedByUserId: $dto->actionUserId,
                sourceBatchId: $dto->sourceBatchId,
                destinationActivityId: $dto->actividadDestinoId,
                destinationMode: count($emitDestinations) === 1 ? TransferOrderEntity::MODE_SINGLE : TransferOrderEntity::MODE_PER_ANIMAL,
                movementDate: substr($dto->fechaMovimiento, 0, 10),
                responsable: $dto->responsable,
                observations: $dto->observaciones,
                destinations: $emitDestinations,
                animals: $animals,
                // The paper decided categories at the chute when any C/S cell was written.
                categoryMode: $this->sheetWroteCategories($dto)
                    ? TransferOrderCategoryMode::AT_CHUTE
                    : TransferOrderCategoryMode::KEEP
            ),
            TransferOrderKind::REGISTERED,
            $note
        );

        $order->issue();

        return $this->repository->save($order, $dto->actionUserId, 'Emitida junto con la carga de la planilla');
    }

    private function sheetWroteCategories(Cact01SubmissionDTO $dto): bool
    {
        foreach ($dto->rows as $row) {
            if ($row['caravana'] !== '' && !AnimalCategoryTextResolver::isBlank($row['cs_nueva'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A second round of an order sends its animals to the batch the first round CREATED, instead
     * of trying to create another one with the same name — which the name check would reject.
     */
    public function adoptResolvedDestinations(?TransferOrderEntity $order, Cact01SubmissionDTO $dto): Cact01SubmissionDTO
    {
        if ($order === null) {
            return $dto;
        }

        $changed = false;
        $destinations = $dto->destinations;

        foreach ($destinations as $index => $destination) {
            if ($destination['new_batch'] === null) {
                continue;
            }

            $declared = $order->destinationByKey(Cact01SubmissionDTO::normalizeKey($destination['new_batch']['name']))
                ?? $order->destinationByKey($destination['key']);

            if ($declared?->getResolvedBatchId() !== null) {
                $destinations[$index] = [
                    'key' => $destination['key'],
                    'target_batch_id' => $declared->getResolvedBatchId(),
                    'new_batch' => null,
                ];
                $changed = true;
            }
        }

        return $changed ? $dto->withDestinations($destinations) : $dto;
    }

    /**
     * Why an animal of the order is no longer in the source batch, when the order knows.
     *
     * Null when the order has nothing to say (the usual NOT_IN_SOURCE_BATCH applies), an empty
     * array when a header error already explains it, and a row error otherwise.
     *
     * @return array{code: string, message: string}|array{}|null
     */
    public function absenceFromSource(?TransferOrderEntity $order, int $caravanId, string $tag): ?array
    {
        $line = $order?->animalByCaravanId($caravanId);

        if ($line === null || $line->getStatus() !== TransferOrderAnimalStatus::MOVED) {
            return null;
        }

        if (!$order->getStatus()->isOpen()) {
            return [];
        }

        return [
            'code' => 'ALREADY_MOVED_BY_ORDER',
            'message' => "La caravana '{$tag}' ya se movió con la orden {$order->getCode()}"
                . ($line->getMovedAt() !== null ? ' el ' . $line->getMovedAt()->format('d/m/Y') : '') . '.',
        ];
    }

    /**
     * An animal that turns up at the chute without being on the roll is normal, so it warns and
     * never blocks.
     *
     * @param array<int, CaravanEntity> $animalsByRow
     * @return list<array{code: string, message: string}>
     */
    public function rowWarnings(?TransferOrderEntity $order, array $animalsByRow): array
    {
        if ($order === null) {
            return [];
        }

        $warnings = [];

        foreach ($animalsByRow as $animal) {
            if ($order->animalByCaravanId((int) $animal->getId()) === null) {
                $warnings[] = [
                    'code' => 'ANIMAL_NOT_IN_ORDER',
                    'message' => "La caravana '{$animal->getIdentification()->getValue()}' no estaba en la orden {$order->getCode()}. Se mueve igual.",
                ];
            }
        }

        return $warnings;
    }

    /**
     * Marks the roll with what this execution moved and recalculates the status.
     *
     * @param array<int, int> $movementIdByCaravanId
     * @param array<string, array{batch: BatchEntity}> $resolvedByKey the destinations of the sheet, by the sheet's key
     * @return array<string, mixed>|null the summary returned with the CACT-01 result
     */
    public function recordExecution(
        ?TransferOrderEntity $order,
        Cact01SubmissionDTO $dto,
        array $movementIdByCaravanId,
        array $resolvedByKey,
        \DateTimeInterface $at
    ): ?array {
        if ($order === null) {
            return null;
        }

        $movedBefore = $order->movedCount();
        $order->recordExecution($movementIdByCaravanId, $this->batchByOrderKey($order, $resolvedByKey), $at);
        $movedNow = $order->movedCount() - $movedBefore;

        $saved = $this->repository->save(
            $order,
            $dto->actionUserId,
            null,
            [
                'origin' => $dto->origin,
                'moved_now' => $movedNow,
                'moved_total' => $order->movedCount(),
                'pending' => $order->pendingCount(),
                'movement_date' => $dto->fechaMovimiento,
            ]
        );

        $pending = array_map(
            fn ($line) => $line->getIdentification() ?? (string) $line->getCaravanId(),
            $saved->pendingAnimals()
        );

        return [
            'id' => $saved->getId(),
            'code' => $saved->getCode(),
            'status' => $saved->getStatus()->value,
            'status_label' => $saved->getStatus()->label(),
            'planned_head_count' => $saved->getPlannedHeadCount(),
            'moved_now' => $movedNow,
            'moved_head_count' => $saved->movedCount(),
            'pending_head_count' => count($pending),
            'pending_identifications' => $pending,
        ];
    }

    /**
     * Which batch each destination OF THE ORDER landed in. The sheet keys its destinations its
     * own way (a handwritten name, or a screen key), so the match is by batch, by name, and by
     * key, in that order.
     *
     * @param array<string, array{batch: BatchEntity}> $resolvedByKey
     * @return array<string, array{id: int, name: string}>
     */
    private function batchByOrderKey(TransferOrderEntity $order, array $resolvedByKey): array
    {
        $result = [];

        foreach ($order->getDestinations() as $destination) {
            foreach ($resolvedByKey as $sheetKey => $entry) {
                $batch = $entry['batch'];
                $matches = ($destination->getTargetBatchId() !== null && $destination->getTargetBatchId() === (int) $batch->getId())
                    || ($destination->getResolvedBatchId() !== null && $destination->getResolvedBatchId() === (int) $batch->getId())
                    || Cact01SubmissionDTO::normalizeKey($batch->getName()) === $destination->getKey()
                    || $sheetKey === $destination->getKey();

                if ($matches) {
                    $result[$destination->getKey()] = ['id' => (int) $batch->getId(), 'name' => $batch->getName()];
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * @return array{field: string, code: string, message: string}
     */
    private function headerError(string $code, string $message): array
    {
        return ['field' => 'orden_transferencia', 'code' => $code, 'message' => $message];
    }
}
