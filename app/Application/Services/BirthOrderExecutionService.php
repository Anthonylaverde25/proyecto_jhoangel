<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\BirthOrders\EmitBirthOrderDTO;
use App\Application\DTOs\Par01\Par01SubmissionDTO;
use App\Core\Entities\BirthOrderAnimalEntity;
use App\Core\Entities\BirthOrderEntity;
use App\Core\Enums\BirthOutcome;
use App\Core\Enums\TransferOrderKind;
use App\Core\Exceptions\BirthOrderDomainException;
use App\Core\Interfaces\IBirthOrderRepository;

/**
 * What a birth order adds to a PAR-01 execution, kept out of the use case that already carries the
 * whole sheet. The mirror of WeaningOrderExecutionService.
 *
 * It is called at two moments. BEFORE registering, to check the order can be executed against.
 * AFTER registering, inside the same transaction, to mark each line with what happened and add the
 * calvings the order did not list. If the order cannot record its execution the calvings are rolled
 * back as well.
 */
final class BirthOrderExecutionService
{
    private const FIELD = 'orden_paricion';

    public function __construct(
        private readonly IBirthOrderRepository $repository,
        private readonly BirthOrderFactory $factory
    ) {
    }

    /**
     * Loads the order the sheet claims to fulfil, adding a header error for every reason it cannot
     * be executed against.
     *
     * Unlike a weaning, a calving may be dated before the order was issued: a round finds calves born
     * days ago. The date is checked per row against the gestation instead.
     *
     * @param array<int, array{field: string, code: string, message: string}> $headerErrors
     */
    public function load(Par01SubmissionDTO $dto, array &$headerErrors): ?BirthOrderEntity
    {
        if ($dto->birthOrderId === null) {
            // A code that resolved to nothing is a misreading or a paper the system never printed;
            // creating a second order over it would hide the first one.
            if ($dto->origin === Par01SubmissionDTO::ORIGIN_SHEET && $dto->paperOrderCode !== null) {
                $headerErrors[] = $this->headerError(
                    'BIRTH_ORDER_NOT_FOUND',
                    "La planilla trae el código {$dto->paperOrderCode} y no existe ninguna orden de parición con ese código. Corregí la lectura, o borralo si la planilla se llenó sin orden."
                );
            }

            return null;
        }

        $order = $this->repository->findById($dto->birthOrderId, $dto->companyId);

        if ($order === null) {
            $headerErrors[] = $this->headerError('BIRTH_ORDER_NOT_FOUND', 'La orden de parición de la planilla no existe.');

            return null;
        }

        if (!$order->getStatus()->isOpen()) {
            $headerErrors[] = $this->headerError(
                'BIRTH_ORDER_NOT_EXECUTABLE',
                $order->getStatus()->isEditable()
                    ? "La orden {$order->getCode()} es un borrador: hay que emitirla antes de ejecutarla."
                    : "La orden {$order->getCode()} está {$order->getStatus()->label()}: no admite más partos."
            );
        }

        return $order;
    }

    /**
     * Whether confirming this sheet has to create its order: a scanned sheet with the code box blank
     * was printed blank, filled at the round and never had one.
     */
    public function needsOrderFromSheet(?BirthOrderEntity $order, Par01SubmissionDTO $dto): bool
    {
        return $order === null && $dto->origin === Par01SubmissionDTO::ORIGIN_SHEET && $dto->paperOrderCode === null;
    }

    /**
     * The order of a sheet that arrived without one, created on confirming it and inside the
     * calvings' transaction. It is a REGISTERED order — the calvings happened before the system knew
     * of them — whose roll is the females the sheet resolved. It is issued without the commitment
     * check on purpose: the sheet is a fact, and a female held elsewhere was already reported per row.
     *
     * @param list<array{mother_id: int, gestation_id: ?int, batch_id: ?int}> $females
     *
     * @throws BirthOrderDomainException
     */
    public function createFromSheet(Par01SubmissionDTO $dto, array $females): BirthOrderEntity
    {
        $animals = array_map(fn (array $female) => new BirthOrderAnimalEntity(
            id: null,
            motherCaravanId: $female['mother_id'],
            gestationId: $female['gestation_id'],
            sourceBatchId: $female['batch_id']
        ), $females);

        $round = $dto->roundDate();

        return $this->factory->create(
            new EmitBirthOrderDTO(
                companyId: $dto->companyId,
                requestedByUserId: $dto->actionUserId,
                periodStart: $round,
                periodEnd: $round,
                responsable: $dto->responsable,
                observations: $dto->observaciones,
                motherIds: array_column($females, 'mother_id'),
                issue: true
            ),
            TransferOrderKind::REGISTERED,
            'Orden creada al confirmar una planilla PAR-01 escaneada que no traía orden',
            checkCommitment: false,
            animals: $animals
        );
    }

    /**
     * Why a female of the order cannot be resolved again: she already was, with it. Null when the
     * order has nothing to say.
     *
     * @return array{code: string, message: string}|null
     */
    public function alreadyResolvedByOrder(?BirthOrderEntity $order, int $motherId, string $tag): ?array
    {
        $line = $order?->animalByMotherId($motherId);

        if ($line === null || $line->isPending()) {
            return null;
        }

        $what = $line->getOutcome()?->label() ?? strtolower($line->getStatus()->name);

        return [
            'code' => 'ALREADY_RESOLVED',
            'message' => "La hembra '{$tag}' ya se registró con la orden {$order->getCode()} ({$what}"
                . ($line->getEventDate() !== null ? ', ' . date('d/m/Y', (int) strtotime($line->getEventDate())) : '') . ').',
        ];
    }

    /**
     * Marks the roll with what this round resolved, adds the unplanned calvings and recalculates the
     * status.
     *
     * @param array<int, array{outcome: BirthOutcome, event_date: string, calf_caravan_id: ?int, calf_batch_id: ?int, observations: ?string}> $resultByMotherId
     * @param list<array{mother_id: int, gestation_id: ?int, batch_id: ?int}> $unplanned
     * @return array<string, mixed>
     *
     * @throws BirthOrderDomainException
     */
    public function recordExecution(
        BirthOrderEntity $order,
        Par01SubmissionDTO $dto,
        array $resultByMotherId,
        array $unplanned,
        \DateTimeInterface $at
    ): array {
        foreach ($unplanned as $female) {
            $order->addUnplanned($female['mother_id'], $female['gestation_id'], $female['batch_id']);
        }

        $resolvedBefore = $order->resolvedCount();
        $order->recordExecution($resultByMotherId, $at);
        $resolvedNow = $order->resolvedCount() - $resolvedBefore;

        $saved = $this->repository->save(
            $order,
            $dto->actionUserId,
            null,
            [
                'origin' => $dto->origin,
                'resolved_now' => $resolvedNow,
                'born_now' => count(array_filter($resultByMotherId, fn (array $r) => $r['outcome'] === BirthOutcome::LIVE)),
                'lost_now' => count(array_filter($resultByMotherId, fn (array $r) => $r['outcome'] !== BirthOutcome::LIVE)),
                'unplanned_now' => count($unplanned),
                'resolved_total' => $order->resolvedCount(),
                'pending' => $order->pendingCount(),
                'round_date' => $dto->roundDate(),
            ]
        );

        $pending = array_map(
            fn (BirthOrderAnimalEntity $line) => $line->getMotherIdentification() ?? (string) $line->getMotherCaravanId(),
            $saved->pendingAnimals()
        );

        return [
            'id' => $saved->getId(),
            'code' => $saved->getCode(),
            'status' => $saved->getStatus()->value,
            'status_label' => $saved->getStatus()->label(),
            'kind' => $saved->getKind()->value,
            'planned_head_count' => $saved->getPlannedHeadCount(),
            'head_count' => count($saved->getAnimals()),
            'resolved_now' => $resolvedNow,
            'resolved_head_count' => $saved->resolvedCount(),
            'born_head_count' => $saved->bornCount(),
            'lost_head_count' => $saved->lostCount(),
            'pending_head_count' => count($pending),
            'pending_identifications' => $pending,
        ];
    }

    /**
     * @return array{field: string, code: string, message: string}
     */
    private function headerError(string $code, string $message): array
    {
        return ['field' => self::FIELD, 'code' => $code, 'message' => $message];
    }
}
