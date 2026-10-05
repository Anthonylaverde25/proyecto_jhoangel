<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

use App\Application\DTOs\Par01\Par01SubmissionDTO;
use App\Application\DTOs\RegisterBirthDTO;
use App\Application\Services\BirthOrderExecutionService;
use App\Application\Services\BreedCoatCatalog;
use App\Application\UseCases\Caravans\RegisterBirthUseCase;
use App\Application\UseCases\Caravans\RegisterGestationLossUseCase;
use App\Application\UseCases\Caravans\RegisterPerinatalDeathUseCase;
use App\Core\Entities\BirthOrderAnimalEntity;
use App\Core\Entities\BirthOrderEntity;
use App\Core\Entities\CaravanEntity;
use App\Core\Entities\GestationEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\BatchWeightCause;
use App\Core\Enums\BirthOutcome;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\Par01ValidationException;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\IBirthOrderRepository;
use App\Core\Interfaces\IBreedRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Services\BatchWeightService;
use App\Core\ValueObjects\BirthSheetMark;
use Illuminate\Support\Facades\DB;

/**
 * PAR-01: the one path that registers calvings. A scanned sheet (one or several pages), an order
 * executed from the screen and calvings registered afterwards all arrive here as the same
 * submission, so the same checks hold for all of them.
 *
 * Each row is a pregnant female and what the round found: a live calf (V) — created in the batch
 * its mother is in, never in one the sheet names —, a calf born dead (NM, charged to the mother), a
 * calf born alive that died at foot (M, charged to the calf), or that she passed her due date
 * without calving (N, an alert that keeps her open). A row without a mark is a female that did not
 * calve yet and stays pending. The abortion is not on this sheet: it is registered in Monitoreo
 * Gestacional.
 *
 * The same sheet is scanned again as the rounds fill it (R1): a female the order already resolved is
 * compared with the paper first and skipped — a difference is a warning, never a blocker.
 *
 * All or nothing for what is new: every problem on the sheet is collected and reported together,
 * and nothing is persisted until the sheet is clean. Every calving ends up in a birth order: the one
 * the sheet names, or one created on confirming a sheet printed blank.
 */
final class ProcessPar01SubmissionUseCase
{
    /** How far from the due date a calving is still unremarkable. */
    private const DUE_DATE_TOLERANCE_DAYS = 30;

    /** A row that registers a calving (V, NM or M). */
    private const ITEM_RESOLVE = 'resolve';
    /** A row that reports a female past her due date without calving (only N). */
    private const ITEM_OVERDUE = 'overdue';

    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly IBatchRepository $batchRepository,
        private readonly IBreedRepository $breedRepository,
        private readonly IBirthOrderRepository $orderRepository,
        private readonly RegisterBirthUseCase $registerBirth,
        private readonly RegisterGestationLossUseCase $registerLoss,
        private readonly RegisterPerinatalDeathUseCase $registerPerinatalDeath,
        private readonly BatchWeightService $batchWeightService,
        private readonly BirthOrderExecutionService $orderExecution
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Par01ValidationException
     * @throws DomainException
     */
    public function __invoke(Par01SubmissionDTO $dto): array
    {
        return $this->persist($this->validate($dto));
    }

    /**
     * Every check of the sheet, collected and thrown together.
     *
     * @return array<string, mixed>
     *
     * @throws Par01ValidationException
     */
    private function validate(Par01SubmissionDTO $dto): array
    {
        $headerErrors = [];
        $warnings = [];
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        if ($dto->fechaRecorrida !== null) {
            $round = $this->parseDate($dto->fechaRecorrida);

            if ($round === null) {
                $headerErrors[] = $this->headerError('fecha_recorrida', 'DATE_INVALID', 'La fecha de recorrida no es una fecha válida.');
            } elseif ($round > $today) {
                $headerErrors[] = $this->headerError('fecha_recorrida', 'FUTURE_DATE', 'La fecha de recorrida no puede ser posterior a hoy.');
            }
        }

        // 1. The birth order the sheet fulfils, when it names one.
        $order = $this->orderExecution->load($dto, $headerErrors);

        // 2. Everything the rows name, in one query each.
        $motherTags = [];
        $calfTags = [];
        foreach ($dto->rows as $row) {
            if ($row['caravana_madre'] !== '') {
                $motherTags[] = $row['caravana_madre'];
            }
            if ($row['caravana_cria'] !== null) {
                $calfTags[] = $row['caravana_cria'];
            }
        }

        $mothersByTag = $this->caravanRepository->findByIdentifications($motherTags);
        $existingCalves = $this->caravanRepository->findByIdentifications($calfTags);
        $motherIds = array_values(array_map(fn (CaravanEntity $c) => (int) $c->getId(), $mothersByTag));
        $committed = $this->orderRepository->findCommittedMothers($motherIds, $dto->companyId, $order?->getId());
        $breeds = BreedCoatCatalog::fromBreeds($this->breedRepository->getAll());
        $fathers = [];
        $lossReasonIds = [];

        /** @var array<int, array<int, array{code: string, message: string, field?: string}>> $errorsByRow */
        $errorsByRow = [];
        $items = [];
        $seenMothers = [];
        $seenCalves = [];
        $rowsWithMother = 0;
        $alreadyRegistered = [];
        $differs = 0;

        foreach ($dto->rows as $index => $row) {
            $tag = $row['caravana_madre'];

            if ($tag === '' && $this->isBlank($row)) {
                // Blank lines of the printed table, not a missing female.
                continue;
            }

            if ($tag === '') {
                $errorsByRow[$index][] = $this->error('MOTHER_MISSING', 'La fila tiene datos pero no dice de qué hembra es.', 'caravana_madre');
                continue;
            }

            $rowsWithMother++;
            $upper = mb_strtoupper($tag);
            $rowErrors = [];

            if (isset($seenMothers[$upper])) {
                $errorsByRow[$index][] = $this->error('DUPLICATED_IN_SHEET', "La hembra '{$tag}' ya figura en la fila " . ($seenMothers[$upper] + 1) . '.', 'caravana_madre');
                continue;
            }
            $seenMothers[$upper] = $index;

            // 3. Nothing written: a female that did not calve yet, or one already registered.
            $mark = BirthSheetMark::parse($row['resultado']);

            if ($mark->isEmpty() && !$this->hasCalfData($row)) {
                continue;
            }

            $mother = $mothersByTag[$upper] ?? null;

            if ($mother === null) {
                $errorsByRow[$index][] = $this->error('MOTHER_NOT_FOUND', "No existe la caravana '{$tag}'.", 'caravana_madre');
                continue;
            }

            if (!$mother->isInPossession()) {
                $errorsByRow[$index][] = $this->error('CARAVAN_IN_TRANSIT', "La caravana '{$tag}' está en tránsito: figura en un DTE pero todavía no se recibió.", 'caravana_madre');
                continue;
            }

            if ($mother->getSex() !== AnimalSex::FEMALE) {
                $errorsByRow[$index][] = $this->error('NOT_A_FEMALE', "La caravana '{$tag}' no es de una hembra.", 'caravana_madre');
                continue;
            }

            $motherId = (int) $mother->getId();
            $line = $order?->animalByMotherId($motherId);

            // 4. R1: what the order already knows goes before any other check — the calf tag in use
            //    included, since the previous load created it.
            $known = $this->orderExecution->reconcile($line, $mark, $row['caravana_cria']);

            if ($known !== null) {
                if ($known['kind'] === BirthOrderExecutionService::ROW_OVERDUE_KEPT && $this->hasCalfData($row, withDate: false)) {
                    $errorsByRow[$index][] = $this->overdueWithCalfData($tag);
                    continue;
                }

                if ($known['kind'] === BirthOrderExecutionService::ROW_DIFFERS) {
                    $differs++;
                    $warnings[] = $this->warning($index, 'ALREADY_RESOLVED_DIFFERS', "'{$tag}': {$known['message']}", 'resultado');
                    continue;
                }

                $alreadyRegistered[] = ['row_index' => $index, 'caravana_madre' => $tag, 'kind' => $known['kind'], 'message' => $known['message']];
                continue;
            }

            // 5. The mark: one of V / NM / M, with or without N, or only N.
            if ($mark->isAbortion()) {
                $errorsByRow[$index][] = $this->error('OUTCOME_NOT_ON_SHEET', "'{$tag}': el aborto no se marca en la planilla de parición. Registralo en Monitoreo Gestacional.", 'resultado');
                continue;
            }

            if ($mark->isAmbiguous()) {
                $errorsByRow[$index][] = $this->error('OUTCOME_UNKNOWN', "El resultado marcado para '{$tag}' ('{$row['resultado']}') no es uno solo de V (parió), NM (nació muerto) o M (murió al pie), con o sin N (no parió).", 'resultado');
                continue;
            }

            $outcome = $mark->outcome();

            if ($outcome === null && !$mark->isOverdue()) {
                $errorsByRow[$index][] = $this->error('OUTCOME_MISSING', "La fila de '{$tag}' tiene datos de cría pero no tiene marcado el resultado: V, NM o M.", 'resultado');
                continue;
            }

            if ($outcome === null) {
                $item = $this->overdueItem($row, $index, $tag, $mother, $line, $order, $today, $warnings, $rowErrors);

                if ($rowErrors !== []) {
                    $errorsByRow[$index] = array_merge($errorsByRow[$index] ?? [], $rowErrors);
                    continue;
                }

                $items[] = $item;
                continue;
            }

            // 6. Its place in the order.
            $holder = $committed[$motherId] ?? null;
            if ($line === null && $holder !== null) {
                $rowErrors[] = $this->error('ANIMAL_IN_OPEN_ORDER', "La hembra '{$tag}' está en la orden de parición {$holder}. Registrala con esa orden.", 'caravana_madre');
            }

            // A calving outside the plan is declared with the "Fuera de orden" box, never inferred
            // from a tag the order does not list: that could just as well be a misread tag.
            if ($line === null && $order !== null) {
                if ($dto->origin !== Par01SubmissionDTO::ORIGIN_SHEET) {
                    $rowErrors[] = $this->error('ANIMAL_NOT_IN_ORDER', "La hembra '{$tag}' no está en la orden {$order->getCode()}.", 'caravana_madre');
                } elseif (!$row['fuera_de_orden']) {
                    $rowErrors[] = $this->error(
                        'OUTSIDE_ORDER_NOT_DECLARED',
                        "'{$tag}' no está en la orden {$order->getCode()}. Si parió sin estar en el plan, marcá «Fuera de orden»; si no, corregí la caravana.",
                        'fuera_de_orden'
                    );
                }
            }

            if ($line !== null && $row['fuera_de_orden']) {
                $warnings[] = $this->warning($index, 'OUTSIDE_ORDER_MARK_IGNORED', "'{$tag}' está en la orden {$order?->getCode()}: la marca «Fuera de orden» no aplica.", 'fuera_de_orden');
            }

            // 7. The gestation it closes.
            $active = $mother->getActiveGestation();

            if ($line !== null && $line->getGestationId() !== null && $active?->getId() !== $line->getGestationId()) {
                $rowErrors[] = $this->error('GESTATION_CLOSED', "La preñez de '{$tag}' que listaba la orden ya se cerró por otro camino. Cerrá la orden incompleta si no queda nada por registrar.", 'resultado');
            }

            $lossCode = $outcome->lossReasonCode();

            if ($lossCode !== null && $active === null) {
                $rowErrors[] = $this->error('NO_ACTIVE_GESTATION', "'{$tag}' no tiene una preñez en curso: no hay pérdida que registrar.", 'resultado');
            }

            if ($lossCode !== null && !array_key_exists($outcome->value, $lossReasonIds)) {
                $lossReasonIds[$outcome->value] = $this->orderRepository->lossReasonIdByCode($lossCode, $dto->companyId);

                if ($lossReasonIds[$outcome->value] === null) {
                    $headerErrors[] = $this->headerError('rows', 'LOSS_REASON_MISSING', "La empresa no tiene configurado el motivo de pérdida '{$outcome->label()}'.");
                }
            }

            // 8. The date, per row: never taken from the header.
            $date = $this->eventDate($row['fecha_nacimiento'], $active, $tag, $index, $today, $rowErrors, $warnings);

            // 9. Where the calf is born: the batch the mother is in now.
            $calfBatchId = $mother->getBatchId() ?? $line?->getSourceBatchId();
            $calf = null;
            $calfSex = null;

            if ($outcome === BirthOutcome::LIVE) {
                if ($line?->getSourceBatchId() !== null && $mother->getBatchId() !== null && $mother->getBatchId() !== $line->getSourceBatchId()) {
                    $batchName = $this->batchRepository->findById((int) $mother->getBatchId())?->getName() ?? 'otro lote';
                    $warnings[] = $this->warning($index, 'MOTHER_MOVED', "'{$tag}' ahora está en {$batchName}: la cría queda ahí, con su madre.");
                }

                if ($calfBatchId === null) {
                    $rowErrors[] = $this->error('MOTHER_WITHOUT_BATCH', "'{$tag}' no está en ningún lote: la cría no tiene dónde nacer.", 'caravana_madre');
                }

                $calf = $this->calf($row, $tag, $index, $active, $existingCalves, $seenCalves, $breeds, $fathers, $rowErrors, $warnings);
            } else {
                // A calf that died: no caravan is created. Its sex is optional and kept on the line.
                $calfSex = $this->sex($row['sexo']);

                if ($row['sexo'] !== null && $calfSex === null) {
                    $rowErrors[] = $this->error('CALF_SEX_UNKNOWN', "El sexo '{$row['sexo']}' de la cría de '{$tag}' no es M (macho) ni H (hembra).", 'sexo');
                }

                if ($row['caravana_cria'] !== null || $row['peso'] !== null || $row['raza'] !== null || $row['pelaje'] !== null) {
                    $warnings[] = $this->warning($index, 'CALF_DATA_IGNORED', "'{$tag}': con {$outcome->label()} no se da de alta ninguna cría; la caravana, el peso, la raza y el pelaje escritos se ignoran.");
                }
            }

            if ($rowErrors !== []) {
                $errorsByRow[$index] = array_merge($errorsByRow[$index] ?? [], $rowErrors);
                continue;
            }

            $items[] = [
                'kind' => self::ITEM_RESOLVE,
                'index' => $index,
                'mother' => $mother,
                'outcome' => $outcome,
                'date' => (string) $date,
                'in_order' => $line !== null,
                'gestation_id' => $active?->getId(),
                'batch_id' => $calfBatchId,
                'calf' => $calf,
                'calf_sex' => $calfSex,
                'observations' => $row['observations'],
                'overdue_since' => $line?->isOverdue() ? $line->getOverdueReportedAt() : null,
                'due_date' => $active?->getEstimatedDueDate(),
            ];
        }

        if ($rowsWithMother === 0) {
            $headerErrors[] = $this->headerError('rows', 'NO_ROWS', 'La planilla no tiene vientres cargados.');
        } elseif ($items === [] && $errorsByRow === []) {
            $headerErrors[] = $alreadyRegistered !== [] || $differs > 0
                ? $this->headerError('rows', 'NOTHING_RESOLVED', 'Todo lo marcado en la planilla ya estaba registrado: no hay novedades que guardar.')
                : $this->headerError('rows', 'NOTHING_RESOLVED', 'Ningún vientre tiene resultado marcado: no hay partos que registrar.');
        }

        $rowErrors = [];
        ksort($errorsByRow);
        foreach ($errorsByRow as $index => $errors) {
            $rowErrors[] = [
                'row_index' => $index,
                'caravana_madre' => $dto->rows[$index]['caravana_madre'],
                'errors' => $errors,
            ];
        }

        if ($headerErrors !== [] || $rowErrors !== []) {
            throw new Par01ValidationException($headerErrors, $rowErrors);
        }

        return [
            'dto' => $dto,
            'order' => $order,
            'items' => $items,
            'loss_reason_ids' => $lossReasonIds,
            'warnings' => $warnings,
            'already_registered' => $alreadyRegistered,
            'differs' => $differs,
        ];
    }

    /**
     * Registers the checked sheet in one transaction: order first (a blank sheet gets one), each
     * calving, each overdue alert, one weight recalculation per batch that received weighed calves,
     * and the order's record of it.
     *
     * @param array<string, mixed> $checked
     * @return array<string, mixed>
     */
    private function persist(array $checked): array
    {
        /** @var Par01SubmissionDTO $dto */
        $dto = $checked['dto'];
        /** @var ?BirthOrderEntity $order */
        $order = $checked['order'];
        $warnings = $checked['warnings'];
        $calvings = array_values(array_filter($checked['items'], fn (array $i) => $i['kind'] === self::ITEM_RESOLVE));
        $overdueItems = array_values(array_filter($checked['items'], fn (array $i) => $i['kind'] === self::ITEM_OVERDUE));

        return DB::transaction(function () use ($dto, $order, $calvings, $overdueItems, $warnings, $checked): array {
            $females = array_map(fn (array $item) => [
                'mother_id' => (int) $item['mother']->getId(),
                'gestation_id' => $item['gestation_id'],
                'batch_id' => $item['mother']->getBatchId(),
            ], $calvings);

            // STEP 0. A sheet printed blank gets its order now, with every female it resolved.
            $createdFromSheet = $this->orderExecution->needsOrderFromSheet($order, $dto);

            if ($createdFromSheet) {
                $order = $this->orderExecution->createFromSheet($dto, $females);
            }

            if ($order === null) {
                throw new DomainException('Los partos se registran siempre con una orden de parición.');
            }

            $unplanned = [];
            foreach ($calvings as $position => $item) {
                if (!$item['in_order'] && !$createdFromSheet) {
                    $unplanned[] = $females[$position];
                }
            }

            // STEP 1. Each calving.
            $results = [];
            $calves = [];
            $weighedBatches = [];
            $counts = ['males' => 0, 'females' => 0];
            $overdueResolved = [];

            foreach ($calvings as $item) {
                /** @var CaravanEntity $mother */
                $mother = $item['mother'];
                $motherId = (int) $mother->getId();
                /** @var BirthOutcome $outcome */
                $outcome = $item['outcome'];
                $calfId = null;

                if ($outcome === BirthOutcome::LIVE) {
                    $calfData = $item['calf'];
                    $created = ($this->registerBirth)(new RegisterBirthDTO(
                        calfIdentification: $calfData['identification'],
                        calfSex: $calfData['sex'],
                        calfCategory: $calfData['sex'] === AnimalSex::MALE->value ? 'ternero' : 'ternera',
                        calfTeeth: $calfData['teeth'],
                        calfWeight: $calfData['weight'],
                        calfBreedId: $calfData['breed_id'],
                        calfColorId: $calfData['color_id'],
                        birthDate: $item['date'],
                        batchId: (int) $item['batch_id'],
                        motherId: $motherId,
                        fatherId: $calfData['father_id'],
                        gestationId: $item['gestation_id']
                    ), false);

                    $calfId = (int) $created->getId();
                    $counts[$calfData['sex'] === AnimalSex::MALE->value ? 'males' : 'females']++;

                    if ($calfData['weight'] !== null) {
                        $weighedBatches[(int) $item['batch_id']] = true;
                    }

                    $calves[] = [
                        'mother' => $mother->getIdentification()->getValue(),
                        'calf' => $calfData['identification'],
                        'sex' => $calfData['sex'],
                        'batch_id' => $created->getBatchId(),
                        'father_id' => $created->getLineage()?->getFatherId(),
                    ];
                } elseif ($outcome === BirthOutcome::PERINATAL_DEATH) {
                    // Charged to the calf: the mother's gestation closes successful.
                    ($this->registerPerinatalDeath)($motherId, $item['date']);
                } else {
                    // Charged to the mother: her gestation closes as a stillbirth.
                    ($this->registerLoss)(
                        $motherId,
                        (int) $checked['loss_reason_ids'][$outcome->value],
                        $item['observations'],
                        $item['date'],
                        promoteToCow: $outcome->isCalving(),
                        fromBirthOrder: true
                    );
                }

                $results[$motherId] = [
                    'outcome' => $outcome,
                    'event_date' => $item['date'],
                    'calf_caravan_id' => $calfId,
                    'calf_batch_id' => $outcome === BirthOutcome::LIVE ? (int) $item['batch_id'] : null,
                    'calf_sex' => $item['calf_sex'],
                    'observations' => $item['observations'],
                ];

                if ($item['overdue_since'] !== null) {
                    $overdueResolved[] = [
                        'mother' => $mother->getIdentification()->getValue(),
                        'overdue_reported_at' => $item['overdue_since'],
                        'event_date' => $item['date'],
                        'days_after_report' => $this->daysBetween($item['overdue_since'], $item['date']),
                        'days_after_due' => $item['due_date'] !== null ? $this->daysBetween($item['due_date'], $item['date']) : null,
                    ];
                }
            }

            // STEP 2. Each overdue alert: on the line and on the female's gestation, where it stays
            // visible even after the order is closed.
            $overdue = [];
            $overdueNew = [];

            foreach ($overdueItems as $item) {
                /** @var CaravanEntity $mother */
                $mother = $item['mother'];
                $mother->getActiveGestation()?->reportCalvingOverdue($item['date']);
                $this->caravanRepository->save($mother);

                $overdue[(int) $mother->getId()] = ['reported_at' => $item['date'], 'notes' => $item['observations']];
                $overdueNew[] = [
                    'mother' => $mother->getIdentification()->getValue(),
                    'overdue_reported_at' => $item['date'],
                    'estimated_due_date' => $item['due_date'],
                ];
            }

            // STEP 3. The compositional effect, once per batch that received weighed calves.
            foreach (array_keys($weighedBatches) as $batchId) {
                $this->batchWeightService->recalculateBatchWeight($batchId, BatchWeightCause::MOVEMENT_IN);
            }

            // STEP 4. The order, in this same transaction: if it cannot record what happened,
            // nothing happened.
            $orderSummary = $this->orderExecution->recordExecution(
                $order,
                $dto,
                $results,
                $overdue,
                $unplanned,
                new \DateTimeImmutable(),
                [
                    'already_registered' => count($checked['already_registered']),
                    'differs' => $checked['differs'],
                    'overdue_resolved' => count($overdueResolved),
                ]
            );
            $orderSummary['created_from_sheet'] = $createdFromSheet;

            if ($orderSummary['pending_head_count'] > 0) {
                $overdueOpen = $orderSummary['overdue_head_count'];
                $warnings[] = $this->warning(
                    null,
                    'BIRTH_ORDER_PARTIAL',
                    "La orden {$orderSummary['code']} queda parcial: faltan {$orderSummary['pending_head_count']} vientre(s) por parir"
                        . ($overdueOpen > 0 ? ", {$overdueOpen} con parto vencido." : '.')
                );
            }

            $batchNames = [];
            foreach ($calves as $position => $calf) {
                $batchId = $calf['batch_id'];
                $batchNames[$batchId] ??= $batchId !== null ? $this->batchRepository->findById((int) $batchId)?->getName() : null;
                $calves[$position]['batch_name'] = $batchNames[$batchId];
            }

            $byOutcome = fn (BirthOutcome $outcome) => count(array_filter($calvings, fn (array $i) => $i['outcome'] === $outcome));

            return [
                'resolved_count' => count($calvings),
                'live_count' => $byOutcome(BirthOutcome::LIVE),
                'stillborn_count' => $byOutcome(BirthOutcome::STILLBORN),
                'perinatal_death_count' => $byOutcome(BirthOutcome::PERINATAL_DEATH),
                'overdue_new_count' => count($overdueNew),
                'overdue_resolved_count' => count($overdueResolved),
                'overdue_open_count' => $orderSummary['overdue_head_count'],
                'already_registered_count' => count($checked['already_registered']),
                'differs_count' => $checked['differs'],
                'males_count' => $counts['males'],
                'females_count' => $counts['females'],
                'unplanned_count' => count($unplanned),
                'calves' => $calves,
                'overdue_new' => $overdueNew,
                'overdue_resolved' => $overdueResolved,
                'already_registered' => $checked['already_registered'],
                'warnings' => array_values($warnings),
                'birth_order' => $orderSummary,
            ];
        });
    }

    /**
     * R3 — only N: the round found her past her due date without calving. It needs an open order
     * where the alert can stay, a female of that order and her current gestation.
     *
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $warnings
     * @param list<array{code: string, message: string, field?: string}> $rowErrors
     * @return array<string, mixed>|null
     */
    private function overdueItem(
        array $row,
        int $index,
        string $tag,
        CaravanEntity $mother,
        ?BirthOrderAnimalEntity $line,
        ?BirthOrderEntity $order,
        string $today,
        array &$warnings,
        array &$rowErrors
    ): ?array {
        if ($this->hasCalfData($row, withDate: false)) {
            $rowErrors[] = $this->overdueWithCalfData($tag);

            return null;
        }

        if ($order === null) {
            $rowErrors[] = $this->error('OVERDUE_NEEDS_ORDER', "'{$tag}': la N (no parió) necesita una orden de parición abierta donde quedar como alerta. Una planilla en blanco sirve para una sola carga: emití la orden para recorridas de varios días.", 'resultado');

            return null;
        }

        if ($line === null) {
            $rowErrors[] = $this->error('OVERDUE_NOT_IN_ORDER', "'{$tag}' no está en la orden {$order->getCode()}: la N avisa de una hembra de la orden que no parió.", 'resultado');

            return null;
        }

        $active = $mother->getActiveGestation();

        if ($active === null) {
            $rowErrors[] = $this->error('NO_ACTIVE_GESTATION', "'{$tag}' no tiene una preñez en curso: no hay parto vencido que avisar.", 'resultado');

            return null;
        }

        if ($line->getGestationId() !== null && $active->getId() !== $line->getGestationId()) {
            $rowErrors[] = $this->error('GESTATION_CLOSED', "La preñez de '{$tag}' que listaba la orden ya se cerró por otro camino.", 'resultado');

            return null;
        }

        $date = $this->eventDate($row['fecha_nacimiento'], $active, $tag, $index, $today, $rowErrors, $warnings, overdue: true);

        return [
            'kind' => self::ITEM_OVERDUE,
            'index' => $index,
            'mother' => $mother,
            'date' => (string) $date,
            'gestation_id' => $active->getId(),
            'due_date' => $active->getEstimatedDueDate(),
            'observations' => $row['observations'],
        ];
    }

    /**
     * @return array{code: string, message: string, field: string}
     */
    private function overdueWithCalfData(string $tag): array
    {
        return $this->error(
            'OUTCOME_MISSING_WITH_OVERDUE',
            "La fila de '{$tag}' tiene datos de cría y sólo la N marcada: si parió, marcá también Parió, Nació muerto o Murió.",
            'resultado'
        );
    }

    private function daysBetween(string $from, string $to): int
    {
        return (int) round(((int) strtotime(substr($to, 0, 10)) - (int) strtotime(substr($from, 0, 10))) / 86400);
    }

    /**
     * The date of a row: required, a real date, not in the future and not before the gestation
     * began. Far from the due date is only a warning — the due date is an estimate.
     *
     * With `$overdue` it is the day she was found not calved: before her due date it is only a
     * warning — she may just not have calved at this round.
     *
     * @param list<array{code: string, message: string, field?: string}> $rowErrors
     * @param list<array<string, mixed>> $warnings
     */
    private function eventDate(?string $raw, ?GestationEntity $active, string $tag, int $index, string $today, array &$rowErrors, array &$warnings, bool $overdue = false): ?string
    {
        if ($raw === null) {
            $rowErrors[] = $overdue
                ? $this->error('DATE_MISSING', "Falta la fecha en que se constató que '{$tag}' no parió.", 'fecha_nacimiento')
                : $this->error('DATE_MISSING', "Falta la fecha del parto de '{$tag}'.", 'fecha_nacimiento');

            return null;
        }

        $date = $this->parseDate($raw);

        if ($date === null) {
            $rowErrors[] = $this->error('DATE_INVALID', "La fecha '{$raw}' de '{$tag}' no es una fecha válida.", 'fecha_nacimiento');

            return null;
        }

        if ($date > $today) {
            $rowErrors[] = $this->error('FUTURE_DATE', "La fecha del parto de '{$tag}' es posterior a hoy.", 'fecha_nacimiento');

            return $date;
        }

        $start = $active?->getStartDate() !== null ? substr((string) $active->getStartDate(), 0, 10) : null;

        if ($start !== null && $date < $start) {
            $rowErrors[] = $this->error('DATE_BEFORE_GESTATION', "La fecha del parto de '{$tag}' es anterior al inicio de su preñez (" . $this->display($start) . ').', 'fecha_nacimiento');

            return $date;
        }

        $due = $active?->getEstimatedDueDate();

        if ($overdue) {
            $early = $due !== null ? $this->daysBetween($date, $due) : 0;

            if ($early > 0) {
                $warnings[] = $this->warning(
                    $index,
                    'OVERDUE_BEFORE_DUE',
                    "Todavía faltan {$early} días para la FPP de '{$tag}' (" . $this->display((string) $due) . '). Si sólo no parió en esta recorrida, dejá la casilla vacía.',
                    'resultado'
                );
            }

            return $date;
        }

        if ($due !== null) {
            $days = (int) round(((int) strtotime($date) - (int) strtotime(substr($due, 0, 10))) / 86400);

            if (abs($days) > self::DUE_DATE_TOLERANCE_DAYS) {
                $warnings[] = $this->warning(
                    $index,
                    'DATE_FAR_FROM_DUE',
                    "El parto de '{$tag}' es " . abs($days) . ' días ' . ($days < 0 ? 'antes' : 'después') . ' de su fecha probable (' . $this->display($due) . ').',
                    'fecha_nacimiento'
                );
            }
        }

        return $date;
    }

    /**
     * The calf of a live calving: tag, sex, weight, breed, coat (pelaje), teeth and, optionally, sire.
     *
     * @param array<string, mixed> $row
     * @param array<string, CaravanEntity> $existingCalves
     * @param array<string, int> $seenCalves
     * @param BreedCoatCatalog $breeds the breeds and the coats each admits
     * @param array<int, ?CaravanEntity> $fathers cache
     * @param list<array{code: string, message: string, field?: string}> $rowErrors
     * @param list<array<string, mixed>> $warnings
     * @return array{identification: string, sex: string, weight: ?float, breed_id: ?int, color_id: ?int, teeth: int, father_id: ?int}|null
     */
    private function calf(
        array $row,
        string $tag,
        int $index,
        ?GestationEntity $active,
        array $existingCalves,
        array &$seenCalves,
        BreedCoatCatalog $breeds,
        array &$fathers,
        array &$rowErrors,
        array &$warnings
    ): ?array {
        $calfTag = $row['caravana_cria'];

        if ($calfTag === null) {
            $rowErrors[] = $this->error('CALF_TAG_MISSING', "Falta la caravana de la cría de '{$tag}'.", 'caravana_cria');
        } else {
            $upper = mb_strtoupper($calfTag);

            if (isset($seenCalves[$upper])) {
                $rowErrors[] = $this->error('CALF_TAG_DUPLICATED', "La caravana de cría '{$calfTag}' ya figura en la fila " . ($seenCalves[$upper] + 1) . '.', 'caravana_cria');
            } elseif (isset($existingCalves[$upper])) {
                $rowErrors[] = $this->error('CALF_TAG_IN_USE', "Ya existe un animal con la caravana '{$calfTag}'.", 'caravana_cria');
            }

            $seenCalves[$upper] = $index;
        }

        $sex = $this->sex($row['sexo']);

        if ($row['sexo'] === null) {
            $rowErrors[] = $this->error('CALF_SEX_MISSING', "Falta el sexo de la cría de '{$tag}'.", 'sexo');
        } elseif ($sex === null) {
            $rowErrors[] = $this->error('CALF_SEX_UNKNOWN', "El sexo '{$row['sexo']}' de la cría de '{$tag}' no es M (macho) ni H (hembra).", 'sexo');
        }

        if ($row['peso'] !== null && $row['peso'] <= 0) {
            $rowErrors[] = $this->error('INVALID_WEIGHT', "El peso de la cría de '{$tag}' tiene que ser mayor a cero. Corregilo o dejalo vacío.", 'peso');
        }

        if ($row['dientes'] < 0) {
            $rowErrors[] = $this->error('INVALID_TEETH', "Los dientes de la cría de '{$tag}' no pueden ser negativos.", 'dientes');
        }

        $breedId = null;
        if ($row['breed_id'] !== null) {
            $breedId = $breeds->hasBreed($row['breed_id']) ? $row['breed_id'] : null;

            if ($breedId === null) {
                $rowErrors[] = $this->error('BREED_UNKNOWN', "La raza elegida para la cría de '{$tag}' no existe.", 'raza');
            }
        } elseif ($row['raza'] !== null) {
            $breedId = $breeds->breedIdByName($row['raza']);

            if ($breedId === null) {
                $rowErrors[] = $this->error('BREED_UNKNOWN', "La raza '{$row['raza']}' de la cría de '{$tag}' no está en el catálogo. Elegila de la lista o dejala vacía.", 'raza');
            }
        }

        // The coat (pelaje), like an entry order's: from the catalog and, with a breed, one it admits.
        $colorId = null;
        if ($row['color_id'] !== null) {
            $colorId = $breeds->hasColor($row['color_id']) ? $row['color_id'] : null;

            if ($colorId === null) {
                $rowErrors[] = $this->error('COLOR_UNKNOWN', "El pelaje elegido para la cría de '{$tag}' no existe.", 'pelaje');
            }
        } elseif ($row['pelaje'] !== null) {
            $colorId = $breeds->colorIdByText($row['pelaje']);

            if ($colorId === null) {
                $rowErrors[] = $this->error('COLOR_UNKNOWN', "El pelaje '{$row['pelaje']}' de la cría de '{$tag}' no está en el catálogo. Elegilo de la lista o dejalo vacío.", 'pelaje');
            }
        }

        if ($colorId !== null && $breedId !== null && !$breeds->admits($breedId, $colorId)) {
            $rowErrors[] = $this->error('COLOR_NOT_OF_BREED', "El pelaje {$breeds->colorName($colorId)} de la cría de '{$tag}' no corresponde a su raza.", 'pelaje');
        }

        // The sire is optional: left empty, the gestation's single or confirmed sire is used, or
        // the calf waits in "Sires pendientes".
        $fatherId = $row['father_id'];
        if ($fatherId !== null) {
            $fathers[$fatherId] ??= $this->caravanRepository->findById($fatherId);
            $father = $fathers[$fatherId];

            if ($father === null) {
                $rowErrors[] = $this->error('FATHER_NOT_FOUND', "El padre elegido para la cría de '{$tag}' no existe.", 'father_id');
            } elseif ($father->getSex() !== AnimalSex::MALE) {
                $rowErrors[] = $this->error('FATHER_NOT_MALE', "El padre elegido para la cría de '{$tag}' no es un macho.", 'father_id');
            } elseif ($active !== null && $active->getSires() !== []) {
                $inService = array_filter($active->getSires(), fn ($sire) => $sire->getSireId() === $fatherId);

                if ($inService === []) {
                    $warnings[] = $this->warning($index, 'FATHER_NOT_IN_SERVICE', "El padre {$father->getIdentification()->getValue()} no estaba entre los toros del servicio de '{$tag}'. Se agrega como padre confirmado.", 'father_id');
                }
            }
        }

        if ($calfTag === null || $sex === null) {
            return null;
        }

        return [
            'identification' => $calfTag,
            'sex' => $sex,
            'weight' => $row['peso'],
            'breed_id' => $breedId,
            'color_id' => $colorId,
            'teeth' => max(0, (int) $row['dientes']),
            'father_id' => $fatherId,
        ];
    }

    private function sex(?string $raw): ?string
    {
        return match (mb_strtoupper(trim((string) $raw))) {
            'M', 'MACHO' => AnimalSex::MALE->value,
            'H', 'HEMBRA', 'F' => AnimalSex::FEMALE->value,
            default => null,
        };
    }

    private function parseDate(string $raw): ?string
    {
        return Par01SubmissionDTO::parseDate($raw);
    }

    private function display(string $date): string
    {
        return date('d/m/Y', (int) strtotime(substr($date, 0, 10)));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isBlank(array $row): bool
    {
        return $row['resultado'] === null && !$this->hasCalfData($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hasCalfData(array $row, bool $withDate = true): bool
    {
        return $row['caravana_cria'] !== null
            || $row['sexo'] !== null
            || $row['peso'] !== null
            || ($withDate && $row['fecha_nacimiento'] !== null);
    }

    /**
     * @return array{field: string, code: string, message: string}
     */
    private function headerError(string $field, string $code, string $message): array
    {
        return ['field' => $field, 'code' => $code, 'message' => $message];
    }

    /**
     * @return array{code: string, message: string, field?: string}
     */
    private function error(string $code, string $message, ?string $field = null): array
    {
        return $field !== null ? ['code' => $code, 'message' => $message, 'field' => $field] : ['code' => $code, 'message' => $message];
    }

    /**
     * @return array{code: string, message: string, row_index: ?int, field?: string}
     */
    private function warning(?int $rowIndex, string $code, string $message, ?string $field = null): array
    {
        $warning = ['code' => $code, 'message' => $message, 'row_index' => $rowIndex];

        return $field !== null ? $warning + ['field' => $field] : $warning;
    }
}
