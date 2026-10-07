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
use App\Core\ValueObjects\BirthSheetMark;

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

    public const ROW_ALREADY = 'already';
    public const ROW_DIFFERS = 'differs';
    public const ROW_OVERDUE_KEPT = 'overdue_kept';

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
     * The order of a sheet that arrived without one: created on confirming it, inside the calvings'
     * transaction, or obtained beforehand from the review (with every female on the sheet, so it stays
     * open for later rounds). It is a REGISTERED order — the calvings happened before the system knew
     * of them — whose roll is the females the sheet resolved. It is issued without the commitment
     * check on purpose: the sheet is a fact, and a female held elsewhere was already reported per row.
     *
     * @param list<array{mother_id: int, gestation_id: ?int, batch_id: ?int}> $females
     *
     * @throws BirthOrderDomainException
     */
    public function createFromSheet(
        Par01SubmissionDTO $dto,
        array $females,
        string $reason = 'Orden creada al confirmar una planilla PAR-01 escaneada que no traía orden'
    ): BirthOrderEntity
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
            $reason,
            checkCommitment: false,
            animals: $animals
        );
    }

    /**
     * R1 — a sheet is reloaded as it fills up: what the order already knows about a female is
     * compared with what the paper says before anything else, and never blocks the sheet.
     *
     * Null when the row is news: a pending female, or an overdue one the paper now says calved.
     * Otherwise what to do with it:
     * - `already`: the paper says what is registered (or nothing, or only the N of an earlier round);
     * - `differs`: the paper says something else — kept as registered, reported as a warning;
     * - `overdue_kept`: an overdue female still marked only with N — the alert stays open.
     *
     * @return array{kind: string, message: string}|null
     */
    public function reconcile(?BirthOrderAnimalEntity $line, BirthSheetMark $mark, ?string $calfTag): ?array
    {
        if ($line === null) {
            return null;
        }

        if ($line->isOverdue()) {
            if (!$mark->isEmpty() && !$mark->isOverdueOnly()) {
                return null;
            }

            return [
                'kind' => self::ROW_OVERDUE_KEPT,
                'message' => 'No parió en fecha · avisado el ' . $this->display($line->getOverdueReportedAt()) . '.',
            ];
        }

        if (!$line->isResolved()) {
            return null;
        }

        $registered = $line->resolvedLabel() . ($line->getCalfIdentification() !== null ? " (cría {$line->getCalfIdentification()})" : '');
        $on = $line->getEventDate() !== null ? ' el ' . $this->display($line->getEventDate()) : '';
        $outcome = $mark->outcome();

        if ($outcome !== null && $line->getOutcome() !== null && $line->matches($outcome, $calfTag)) {
            return ['kind' => self::ROW_ALREADY, 'message' => "Ya registrada{$on}: {$registered}."];
        }

        if ($outcome === null && !$mark->isAbortion() && !$mark->isAmbiguous()) {
            // Blank, or only the N of an earlier round: resolved from the screen or by a loss.
            $how = $line->getLossReasonCode() !== null ? $line->resolvedLabel() : "Registrada{$on}: {$registered}";

            return ['kind' => self::ROW_ALREADY, 'message' => "{$how}."];
        }

        $paper = match (true) {
            $outcome === null => $mark->isAbortion() ? 'Aborto' : "«{$mark->describe()}»",
            $outcome === BirthOutcome::LIVE && $calfTag !== null => "{$outcome->label()} (cría {$calfTag})",
            default => $outcome->label(),
        };

        return [
            'kind' => self::ROW_DIFFERS,
            'message' => "La planilla dice {$paper}; ya se registró {$registered}{$on}. Se conserva lo registrado.",
        ];
    }

    /**
     * Marks the roll with what this round resolved and the females it found overdue, adds the
     * unplanned calvings and recalculates the status.
     *
     * @param array<int, array{outcome: BirthOutcome, event_date: string, calf_caravan_id: ?int, calf_batch_id: ?int, observations: ?string, calf_sex: ?string}> $resultByMotherId
     * @param array<int, array{reported_at: string, notes: ?string}> $overdueByMotherId
     * @param list<array{mother_id: int, gestation_id: ?int, batch_id: ?int}> $unplanned
     * @param array<string, int> $reconciled how many rows were already registered, differed or were overdue resolved
     * @return array<string, mixed>
     *
     * @throws BirthOrderDomainException
     */
    public function recordExecution(
        BirthOrderEntity $order,
        Par01SubmissionDTO $dto,
        array $resultByMotherId,
        array $overdueByMotherId,
        array $unplanned,
        \DateTimeInterface $at,
        array $reconciled = []
    ): array {
        foreach ($unplanned as $female) {
            $order->addUnplanned($female['mother_id'], $female['gestation_id'], $female['batch_id']);
        }

        $resolvedBefore = $order->resolvedCount();
        $order->recordExecution($resultByMotherId, $at, $overdueByMotherId);
        $resolvedNow = $order->resolvedCount() - $resolvedBefore;

        $saved = $this->repository->save(
            $order,
            $dto->actionUserId,
            null,
            [
                'origin' => $dto->origin,
                'resolved_now' => $resolvedNow,
                'born_now' => count(array_filter($resultByMotherId, fn (array $r) => $r['outcome'] === BirthOutcome::LIVE)),
                'stillborn_now' => count(array_filter($resultByMotherId, fn (array $r) => $r['outcome'] === BirthOutcome::STILLBORN)),
                'born_died_now' => count(array_filter($resultByMotherId, fn (array $r) => $r['outcome'] === BirthOutcome::PERINATAL_DEATH)),
                'unplanned_now' => count($unplanned),
                'overdue_now' => count($overdueByMotherId),
                'overdue_resolved' => $reconciled['overdue_resolved'] ?? 0,
                'already_registered' => $reconciled['already_registered'] ?? 0,
                'differs' => $reconciled['differs'] ?? 0,
                'resolved_total' => $order->resolvedCount(),
                'pending' => $order->pendingCount(),
                'overdue' => $order->overdueCount(),
                'round_date' => $dto->roundDate(),
            ]
        );

        $identification = fn (BirthOrderAnimalEntity $line) => $line->getMotherIdentification() ?? (string) $line->getMotherCaravanId();
        $open = $saved->openAnimals();

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
            'born_died_head_count' => $saved->bornDiedCount(),
            'lost_head_count' => $saved->lostCount(),
            'pending_head_count' => count($open),
            'overdue_head_count' => $saved->overdueCount(),
            'pending_identifications' => array_map($identification, $open),
            'overdue_animals' => array_map(fn (BirthOrderAnimalEntity $line) => [
                'identification' => $identification($line),
                'overdue_reported_at' => $line->getOverdueReportedAt(),
                'estimated_due_date' => $line->getEstimatedDueDate(),
            ], array_values(array_filter($open, fn (BirthOrderAnimalEntity $line) => $line->isOverdue()))),
        ];
    }

    private function display(?string $date): string
    {
        return $date !== null ? date('d/m/Y', (int) strtotime(substr($date, 0, 10))) : '';
    }

    /**
     * @return array{field: string, code: string, message: string}
     */
    private function headerError(string $code, string $message): array
    {
        return ['field' => self::FIELD, 'code' => $code, 'message' => $message];
    }
}
