<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\Cact01\Cact01SubmissionDTO;
use App\Application\DTOs\Dest01\Dest01SubmissionDTO;
use App\Application\DTOs\WeaningOrders\EmitWeaningOrderDTO;
use App\Core\Entities\BatchEntity;
use App\Core\Entities\CaravanEntity;
use App\Core\Entities\WeaningOrderEntity;
use App\Core\Enums\TransferOrderCategoryMode;
use App\Core\Enums\TransferOrderKind;
use App\Core\Enums\WeaningOrderAnimalStatus;
use App\Core\Enums\WeaningType;
use App\Core\Exceptions\WeaningOrderDomainException;
use App\Core\Interfaces\IWeaningOrderRepository;
use App\Core\Services\AnimalCategoryTextResolver;

/**
 * What a weaning order adds to a DEST-01 execution, kept out of the use case that already carries
 * the whole sheet. The mirror of TransferOrderExecutionService.
 *
 * It is called at two moments. BEFORE weaning, to check the order can be executed against and to
 * let a second round reuse the batches the first one created. AFTER weaning, inside the same
 * transaction, to mark each line with the movement that fulfilled it. If the order cannot record
 * its execution the weaning is rolled back as well.
 */
final class WeaningOrderExecutionService
{
    private const FIELD = 'orden_destete';

    public function __construct(
        private readonly IWeaningOrderRepository $repository,
        private readonly WeaningOrderFactory $factory
    ) {
    }

    /**
     * Loads the order the sheet claims to fulfil, adding a header error for every reason it cannot
     * be executed against.
     *
     * @param array<int, array{field: string, code: string, message: string}> $headerErrors
     */
    public function load(Dest01SubmissionDTO $dto, array &$headerErrors): ?WeaningOrderEntity
    {
        if ($dto->weaningOrderId === null) {
            // A code that resolved to nothing is a misreading or a paper the system never printed;
            // creating a second order over it would hide the first one.
            if ($dto->origin === Dest01SubmissionDTO::ORIGIN_SHEET && $dto->paperOrderCode !== null) {
                $headerErrors[] = $this->headerError(
                    'WEANING_ORDER_NOT_FOUND',
                    "La planilla trae el código {$dto->paperOrderCode} y no existe ninguna orden de destete con ese código. Corregí la lectura, o borralo si la planilla se llenó sin orden."
                );
            }

            return null;
        }

        $order = $this->repository->findById($dto->weaningOrderId, $dto->companyId);

        if ($order === null) {
            $headerErrors[] = $this->headerError('WEANING_ORDER_NOT_FOUND', 'La orden de destete de la planilla no existe.');

            return null;
        }

        if (!$order->getStatus()->isOpen()) {
            $headerErrors[] = $this->headerError(
                'WEANING_ORDER_NOT_EXECUTABLE',
                $order->getStatus()->isEditable()
                    ? "La orden {$order->getCode()} es un borrador: hay que emitirla antes de ejecutarla."
                    : "La orden {$order->getCode()} está {$order->getStatus()->label()}: no admite más destetes."
            );
        }

        // A planned order cannot be fulfilled before it existed. What happened earlier is loaded
        // with "Registrar destete", whose order is born with the weaning.
        $emittedOn = $order->getEmittedAt()?->format('Y-m-d');

        if ($order->getKind() === TransferOrderKind::PLANNED && $emittedOn !== null
            && substr($dto->fechaDestete, 0, 10) < $emittedOn) {
            $headerErrors[] = [
                'field' => 'fecha_destete',
                'code' => 'WEANING_BEFORE_ORDER',
                'message' => "La orden {$order->getCode()} se emitió el " . date('d/m/Y', (int) strtotime($emittedOn))
                    . '. El destete no puede tener una fecha anterior.',
            ];
        }

        return $order;
    }

    /**
     * Whether confirming this sheet has to create its order: a scanned sheet with the code box
     * blank was printed blank, filled at the chute and never had one.
     */
    public function needsOrderFromSheet(?WeaningOrderEntity $order, Dest01SubmissionDTO $dto): bool
    {
        return $order === null && $dto->origin === Dest01SubmissionDTO::ORIGIN_SHEET && $dto->paperOrderCode === null;
    }

    /**
     * The order of a sheet that arrived without one, created on confirming it and inside the
     * weaning's transaction. It is a REGISTERED order — the weaning happened before the system knew
     * of it — whose roll is the rows of the sheet. It is issued without the commitment check on
     * purpose: the sheet is a fact, and a calf committed elsewhere was already reported per row.
     *
     * Called with the calves still at foot and the new batches not yet created.
     *
     * @param array<string, array{target_batch_id: ?int, new_batch: ?array{name: string, is_confined: ?bool}, label: string}> $destinations validated, by key
     * @param array<int, CaravanEntity> $calvesByRow
     * @param array<int, string> $destinationKeyByRow
     *
     * @throws WeaningOrderDomainException
     */
    public function createFromSheet(
        Dest01SubmissionDTO $dto,
        array $destinations,
        array $calvesByRow,
        array $destinationKeyByRow,
        ?WeaningType $weaningType
    ): WeaningOrderEntity {
        $emitDestinations = [];
        foreach ($destinations as $key => $destination) {
            $emitDestinations[] = [
                'key' => (string) $key,
                'label' => $destination['label'],
                'target_batch_id' => $destination['target_batch_id'],
                'new_batch_name' => $destination['new_batch']['name'] ?? null,
                'is_confined' => $destination['new_batch']['is_confined'] ?? null,
            ];
        }

        $animals = [];
        foreach ($calvesByRow as $index => $calf) {
            $animals[] = [
                'caravan_id' => (int) $calf->getId(),
                'destination_key' => $destinationKeyByRow[$index],
                'target_category_id' => null,
                'target_subcategory_id' => null,
            ];
        }

        return $this->factory->create(
            new EmitWeaningOrderDTO(
                companyId: $dto->companyId,
                requestedByUserId: $dto->actionUserId,
                destinationMode: count($emitDestinations) === 1 ? WeaningOrderEntity::MODE_SINGLE : WeaningOrderEntity::MODE_PER_ANIMAL,
                weaningDate: substr($dto->fechaDestete, 0, 10),
                weaningType: $weaningType,
                responsable: $dto->responsable,
                observations: $dto->observaciones,
                destinations: $emitDestinations,
                animals: $animals,
                issue: true,
                // The paper decided categories at the chute when any C/S cell was written.
                categoryMode: $this->sheetWroteCategories($dto)
                    ? TransferOrderCategoryMode::AT_CHUTE
                    : TransferOrderCategoryMode::KEEP
            ),
            TransferOrderKind::REGISTERED,
            'Orden creada al confirmar una planilla DEST-01 escaneada que no traía orden',
            checkCommitment: false
        );
    }

    /**
     * A second round of an order sends its calves to the batch the first round CREATED, instead of
     * trying to create another one with the same name — which the name check would reject.
     */
    public function adoptResolvedDestinations(?WeaningOrderEntity $order, Dest01SubmissionDTO $dto): Dest01SubmissionDTO
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
     * Why a calf of the order is no longer at foot, when the order knows: it was already weaned
     * with it. Null when the order has nothing to say.
     *
     * @return array{code: string, message: string}|null
     */
    public function alreadyWeanedByOrder(?WeaningOrderEntity $order, int $caravanId, string $tag): ?array
    {
        $line = $order?->animalByCaravanId($caravanId);

        if ($line === null || $line->getStatus() !== WeaningOrderAnimalStatus::WEANED) {
            return null;
        }

        return [
            'code' => 'ALREADY_WEANED_BY_ORDER',
            'message' => "La cría '{$tag}' ya se destetó con la orden {$order->getCode()}"
                . ($line->getWeanedAt() !== null ? ' el ' . $line->getWeanedAt()->format('d/m/Y') : '') . '.',
        ];
    }

    /**
     * A calf that turns up at the chute without being on the roll is normal, so it warns and never
     * blocks.
     *
     * @param array<int, CaravanEntity> $calvesByRow
     * @return list<array{code: string, message: string}>
     */
    public function rowWarnings(?WeaningOrderEntity $order, array $calvesByRow): array
    {
        if ($order === null) {
            return [];
        }

        $warnings = [];

        foreach ($calvesByRow as $calf) {
            if ($order->animalByCaravanId((int) $calf->getId()) === null) {
                $warnings[] = [
                    'code' => 'ANIMAL_NOT_IN_ORDER',
                    'message' => "La cría '{$calf->getIdentification()->getValue()}' no estaba en la orden {$order->getCode()}. Se desteta igual.",
                ];
            }
        }

        return $warnings;
    }

    /**
     * Marks the roll with what this execution weaned and recalculates the status.
     *
     * @param array<int, int> $movementIdByCaravanId
     * @param array<string, array{batch: BatchEntity}> $resolvedByKey the destinations of the sheet, by the sheet's key
     * @return array<string, mixed>|null the summary returned with the DEST-01 result
     */
    public function recordExecution(
        ?WeaningOrderEntity $order,
        Dest01SubmissionDTO $dto,
        array $movementIdByCaravanId,
        array $resolvedByKey,
        \DateTimeInterface $at,
        ?WeaningType $weaningType,
        array $categoryByCaravanId = []
    ): ?array {
        if ($order === null) {
            return null;
        }

        $weanedBefore = $order->weanedCount();
        $order->declareWeaningTypeIfMissing($weaningType);
        $order->recordExecution($movementIdByCaravanId, $this->batchByOrderKey($order, $resolvedByKey), $at, $categoryByCaravanId);
        $weanedNow = $order->weanedCount() - $weanedBefore;

        $saved = $this->repository->save(
            $order,
            $dto->actionUserId,
            null,
            [
                'origin' => $dto->origin,
                'weaned_now' => $weanedNow,
                'weaned_total' => $order->weanedCount(),
                'pending' => $order->pendingCount(),
                'weaning_date' => substr($dto->fechaDestete, 0, 10),
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
            'kind' => $saved->getKind()->value,
            'planned_head_count' => $saved->getPlannedHeadCount(),
            'weaned_now' => $weanedNow,
            'weaned_head_count' => $saved->weanedCount(),
            'pending_head_count' => count($pending),
            'pending_identifications' => $pending,
        ];
    }

    private function sheetWroteCategories(Dest01SubmissionDTO $dto): bool
    {
        foreach ($dto->rows as $row) {
            if ($row['caravana'] !== '' && !AnimalCategoryTextResolver::isBlank($row['cs_nueva'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which batch each destination OF THE ORDER landed in. The sheet keys its destinations its own
     * way, so the match is by batch, by name and by key, in that order.
     *
     * @param array<string, array{batch: BatchEntity}> $resolvedByKey
     * @return array<string, array{id: int, name: string}>
     */
    private function batchByOrderKey(WeaningOrderEntity $order, array $resolvedByKey): array
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
        return ['field' => self::FIELD, 'code' => $code, 'message' => $message];
    }
}
