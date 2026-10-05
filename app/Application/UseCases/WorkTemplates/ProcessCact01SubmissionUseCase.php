<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

use App\Application\DTOs\Cact01\Cact01SubmissionDTO;
use App\Application\DTOs\CreateBatchDTO;
use App\Application\Services\TransferOrderExecutionService;
use App\Application\UseCases\Batches\CreateBatchUseCase;
use App\Core\Entities\BatchEntity;
use App\Core\Entities\CaravanEntity;
use App\Core\Entities\CaravanMovementEntity;
use App\Core\Entities\CaravanWeightEntity;
use App\Core\Entities\TransferOrderEntity;
use App\Core\Enums\AnimalDentition;
use App\Core\Enums\BatchWeightCause;
use App\Core\Exceptions\Cact01ValidationException;
use App\Core\Exceptions\DomainException;
use App\Core\Entities\AnimalCategoryEntity;
use App\Core\Entities\AnimalSubcategoryEntity;
use App\Core\Enums\TransferOrderCategoryMode;
use App\Core\Interfaces\IActivityRepository;
use App\Core\Interfaces\IAnimalCategoryRepository;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanMovementRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\ICaravanWeightRepository;
use App\Core\Interfaces\ICompanyRepository;
use App\Core\Interfaces\IFarmRepository;
use App\Core\Services\AnimalCategoryResolution;
use App\Core\Services\AnimalCategoryTextResolver;
use App\Core\Services\BatchWeightService;
use App\Core\Services\CaravanValueParser;
use Illuminate\Support\Facades\DB;

/**
 * CACT-01: turns a change-of-activity sheet (one or several scanned pages) into the
 * movement of every animal listed, plus the measurements taken at the chute.
 *
 * Two things make this different from the transfer screen, and both are the reason it
 * cannot delegate to BulkTransferCaravansUseCase:
 *
 * 1. Several destinations in one pass. That use case resolves exactly one target batch,
 *    so calling it once per destination would write a closing point over a source batch
 *    that already lost animals on the previous round — a false close.
 * 2. It records weights. A transfer never writes to caravan_weights; this sheet does,
 *    and the ORDER of the writes is the whole design (see step 4).
 *
 * All or nothing: every problem is collected and reported together, and nothing is
 * persisted until the sheet is clean.
 */
final class ProcessCact01SubmissionUseCase
{
    private const NON_PRODUCTIVE_ACTIVITY = 'INTERNAL';

    /** Destination activities a pregnant female is warned about: finishing, and the internal ones (consumption, death). */
    private const CULL_ACTIVITIES = ['INVERNADA', 'INTERNAL'];

    /** Subcategories that mean the female leaves the breeding herd. */
    private const CULL_SUBCATEGORIES = ['DESCARTE_CUT', 'DESCARTE_FAENA'];

    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly IBatchRepository $batchRepository,
        private readonly IActivityRepository $activityRepository,
        private readonly ICaravanMovementRepository $movementRepository,
        private readonly ICaravanWeightRepository $caravanWeightRepository,
        private readonly IFarmRepository $farmRepository,
        private readonly ICompanyRepository $companyRepository,
        private readonly BatchWeightService $batchWeightService,
        private readonly CreateBatchUseCase $createBatch,
        private readonly TransferOrderExecutionService $orderExecution,
        private readonly IAnimalCategoryRepository $categoryRepository
    ) {
    }

    /**
     * @return array{source: array<string, mixed>, destinations: list<array<string, mixed>>, warnings: list<array{code: string, message: string}>}
     *
     * @throws Cact01ValidationException
     * @throws DomainException
     */
    public function __invoke(Cact01SubmissionDTO $dto): array
    {
        $checked = $this->validate($dto);

        return $this->persist(
            $checked['dto'],
            $checked['source_batch'],
            $checked['destinations'],
            $checked['animals_by_row'],
            $checked['teeth_by_row'],
            $checked['movement_date'],
            $checked['warnings'],
            $checked['order'],
            $checked['category_by_row']
        );
    }

    /**
     * "Obtener orden de transferencia": the sheet names no order that exists, and the operator
     * asks for one generated from the paper BEFORE confirming, so the review goes on against a
     * real order with a real code.
     *
     * Whatever the code box says is ignored — that code is why an order is being asked for. The
     * sheet goes through exactly the checks confirming it would, so the order is never created
     * for a sheet that could not be executed afterwards. Nothing moves: the order is issued and
     * waits for "Confirmar movimiento".
     *
     * @throws Cact01ValidationException
     * @throws DomainException
     */
    public function obtainOrder(Cact01SubmissionDTO $dto): TransferOrderEntity
    {
        $checked = $this->validate($dto->withoutOrder());

        return DB::transaction(fn (): TransferOrderEntity => $this->orderExecution->createFromSheet(
            $checked['dto'],
            $checked['destinations'],
            $checked['animals_by_row'],
            'Orden obtenida desde la revisión de una planilla escaneada que no traía una orden existente'
        ));
    }

    /**
     * Every check of the sheet, collected and thrown together. Shared by confirming the sheet
     * and by obtaining its order, so both answer the same about the same paper.
     *
     * @return array{dto: Cact01SubmissionDTO, source_batch: BatchEntity, destinations: array<string, array<string, mixed>>, animals_by_row: array<int, CaravanEntity>, teeth_by_row: array<int, int>, category_by_row: array<int, array{category: AnimalCategoryEntity, subcategory: ?AnimalSubcategoryEntity}>, movement_date: string, warnings: list<array{code: string, message: string}>, order: ?TransferOrderEntity}
     *
     * @throws Cact01ValidationException
     */
    private function validate(Cact01SubmissionDTO $dto): array
    {
        $headerErrors = [];
        $warnings = [];

        $movementDate = substr($dto->fechaMovimiento, 0, 10);
        if ($movementDate > (new \DateTimeImmutable('today'))->format('Y-m-d')) {
            $headerErrors[] = $this->headerError('fecha_movimiento', 'FUTURE_DATE', 'La fecha del movimiento no puede ser posterior a hoy.');
        }

        // 1. Source batch.
        $sourceBatch = $this->batchRepository->findById($dto->sourceBatchId);
        if ($sourceBatch === null || !$sourceBatch->isActive()) {
            $sourceBatch = null;
            $headerErrors[] = $this->headerError('lote_origen', 'SOURCE_BATCH_NOT_FOUND', 'El lote de origen no existe o está cerrado.');
        }

        $activitiesById = $this->activitiesById();

        if ($sourceBatch !== null && $dto->actividadOrigen !== null) {
            $declared = $activitiesById[$sourceBatch->getActivityId()] ?? null;
            if ($declared !== null && !$this->sameActivity($dto->actividadOrigen, $declared->getName(), $declared->getCode())) {
                $warnings[] = $this->warning(
                    'ACTIVITY_MISMATCH',
                    "La planilla dice que el origen es '{$dto->actividadOrigen}', pero el lote '{$sourceBatch->getName()}' es de {$declared->getName()}. Vale el lote."
                );
            }
        }

        // 1b. The transfer order the sheet fulfils, when it names one. Loaded before the
        //     destinations so a second round can reuse the batches the first one created.
        $order = $this->orderExecution->load($dto, $headerErrors);
        $dto = $this->orderExecution->adoptResolvedDestinations($order, $dto);

        // 2. Destinations. They arrive resolved; what is checked here is that each one
        //    points somewhere real, somewhere different, and somewhere only once.
        $destinations = $this->resolveDestinations($dto, $sourceBatch, $activitiesById, $headerErrors, $warnings);

        // Keys the operator DECLARED, whether or not they survived validation. A key that
        // was declared and then rejected already has a header error explaining why;
        // repeating it as a row error on each of its animals would bury the one message
        // that matters under a hundred that do not.
        $declaredKeys = [];
        foreach ($dto->destinations as $destination) {
            $declaredKeys[$destination['key']] = true;
        }

        // 3. Rows. Every problem per animal is collected, never thrown one at a time.
        $identifications = [];
        foreach ($dto->rows as $row) {
            if ($row['caravana'] !== '') {
                $identifications[] = $row['caravana'];
            }
        }

        // One query for the whole troop: a page of 200 head is not 200 round trips.
        $caravansByTag = $this->caravanRepository->findByIdentifications($identifications);

        /** @var array<int, CaravanEntity> $animalsByRow */
        $animalsByRow = [];
        /** @var array<int, int> $teethByRow */
        $teethByRow = [];
        /**
         * The category an animal changes to, only when it differs from the one it has. It comes
         * from the C/S cell of the sheet, from the order when it declared it, or from
         * "Registrar transferencia".
         *
         * @var array<int, array{category: AnimalCategoryEntity, subcategory: ?AnimalSubcategoryEntity}> $categoryByRow
         */
        $categoryByRow = [];
        $categoryResolver = new AnimalCategoryTextResolver($this->categoryRepository->all());
        /** @var array<int, array<int, array{code: string, message: string}>> $errorsByRow */
        $errorsByRow = [];
        $seenTags = [];

        foreach ($dto->rows as $index => $row) {
            $tag = $row['caravana'];
            if ($tag === '') {
                // Blank lines of the printed table, not a missing animal.
                continue;
            }

            $key = mb_strtoupper($tag);
            if (isset($seenTags[$key])) {
                $errorsByRow[$index][] = $this->error('DUPLICATED_IN_SHEET', "La caravana '{$tag}' ya figura en la fila " . ($seenTags[$key] + 1) . '.');
                continue;
            }
            $seenTags[$key] = $index;

            if (!array_key_exists($row['destination_key'], $destinations)
                && !isset($declaredKeys[$row['destination_key']])) {
                $errorsByRow[$index][] = $row['destination_key'] === ''
                    ? $this->error('DESTINATION_MISSING', "La fila de '{$tag}' no tiene lote de destino: ni en la celda ni en el encabezado.")
                    : $this->error('UNKNOWN_DESTINATION', "El destino '{$row['destination_key']}' de la caravana '{$tag}' no está entre los destinos confirmados.");
            }

            if ($row['peso_actual'] !== null && $row['peso_actual'] <= 0) {
                $errorsByRow[$index][] = $this->error('INVALID_WEIGHT', "El peso de '{$tag}' tiene que ser mayor a cero.");
            }

            $animal = $caravansByTag[$key] ?? null;
            if ($animal === null) {
                $errorsByRow[$index][] = $this->error('NOT_FOUND', "No existe la caravana '{$tag}'.");
                continue;
            }

            if (!$animal->isInPossession()) {
                $errorsByRow[$index][] = $this->error('CARAVAN_IN_TRANSIT', "La caravana '{$tag}' está en tránsito: figura en un DTE pero todavía no se recibió.");
                continue;
            }

            if ($sourceBatch !== null && $animal->getBatchId() !== $sourceBatch->getId()) {
                // The order may know why: the animal already travelled with it.
                $explained = $this->orderExecution->absenceFromSource($order, (int) $animal->getId(), $tag);

                if ($explained === null) {
                    $errorsByRow[$index][] = $this->error(
                        'NOT_IN_SOURCE_BATCH',
                        "La caravana '{$tag}' no está en el lote '{$sourceBatch->getName()}'."
                    );
                } elseif ($explained !== []) {
                    $errorsByRow[$index][] = $explained;
                }
            }

            // Dentition. parseTeeth() never fails: it answers 0 for a blank cell and 0
            // for unreadable text alike, so the two cases have to be told apart BEFORE
            // parsing. Only text that resolves to a real dentition is accepted.
            if ($row['dientes'] !== null) {
                $parsed = CaravanValueParser::parseTeeth($row['dientes']);

                if (AnimalDentition::tryFrom($parsed) === null || !$this->looksLikeDentition($row['dientes'])) {
                    $errorsByRow[$index][] = $this->error(
                        'INVALID_TEETH',
                        "No se entiende la dentición '{$row['dientes']}' de la caravana '{$tag}'. Se esperaba DL, 2D, 4D, 6D, 8D o Boca Llena."
                    );
                } else {
                    $teethByRow[$index] = $parsed;
                }
            }

            $categoryErrors = [];
            $change = $this->categoryChange($row, $animal, $order, $categoryResolver, $categoryErrors, $warnings);

            foreach ($categoryErrors as $categoryError) {
                $errorsByRow[$index][] = $categoryError;
            }

            if ($change !== null) {
                $categoryByRow[$index] = $change;
            }

            $animalsByRow[$index] = $animal;
        }

        if ($seenTags === []) {
            $headerErrors[] = $this->headerError('rows', 'NO_ANIMALS', 'La planilla no tiene animales cargados.');
        }

        // Advisory row-level mismatches: what the paper says about what the animal IS
        // does not overwrite the system. A difference is worth showing, not writing.
        // The sex is not even compared: a transfer moves animals the business already has,
        // their sex is the one the tag identifies and the sheet prints it only to be read.
        foreach ($animalsByRow as $index => $animal) {
            $row = $dto->rows[$index];
            $tag = $row['caravana'];

            if ($row['categoria'] !== null && $animal->getCategoryName() !== null
                && $this->categoryDiffers($row['categoria'], $animal, $categoryResolver)) {
                $warnings[] = $this->warning('CATEGORY_MISMATCH', "El papel dice que '{$tag}' es {$row['categoria']}, el sistema dice {$this->currentCategoryLabel($animal)}. No se modifica.");
            }

            if (isset($teethByRow[$index]) && $teethByRow[$index] < $animal->getTeeth()) {
                $warnings[] = $this->warning(
                    'TEETH_REGRESSION',
                    "La dentición leída para '{$tag}' ({$teethByRow[$index]}) es menor que la registrada ({$animal->getTeeth()}). La dentición avanza, así que no se modifica."
                );
                unset($teethByRow[$index]);
            }
        }

        $warnings = array_merge(
            $warnings,
            $this->zootechnicalWarnings($dto, $animalsByRow, $categoryByRow, $activitiesById),
            $this->sheetTotalWarnings($dto, count($animalsByRow)),
            $this->orderExecution->rowWarnings($order, $animalsByRow)
        );

        $rowErrors = [];
        ksort($errorsByRow);
        foreach ($errorsByRow as $index => $errors) {
            $rowErrors[] = [
                'row_index' => $index,
                'caravana' => $dto->rows[$index]['caravana'],
                'errors' => $errors,
            ];
        }

        if ($headerErrors !== [] || $rowErrors !== []) {
            throw new Cact01ValidationException($headerErrors, $rowErrors);
        }

        /** @var BatchEntity $sourceBatch */
        return [
            'dto' => $dto,
            'source_batch' => $sourceBatch,
            'destinations' => $destinations,
            'animals_by_row' => $animalsByRow,
            'teeth_by_row' => $teethByRow,
            'category_by_row' => $categoryByRow,
            'movement_date' => $movementDate,
            'warnings' => $warnings,
            'order' => $order,
        ];
    }

    /**
     * Writes the sheet in the one order that keeps the two effects readable apart.
     *
     * @param array<string, array<string, mixed>> $destinations
     * @param array<int, CaravanEntity> $animalsByRow
     * @param array<int, int> $teethByRow
     * @param list<array{code: string, message: string}> $warnings
     * @param array<int, array{category: AnimalCategoryEntity, subcategory: ?AnimalSubcategoryEntity}> $categoryByRow
     * @return array{source: array<string, mixed>, destinations: list<array<string, mixed>>, warnings: list<array{code: string, message: string}>}
     */
    private function persist(
        Cact01SubmissionDTO $dto,
        BatchEntity $sourceBatch,
        array $destinations,
        array $animalsByRow,
        array $teethByRow,
        string $movementDate,
        array $warnings,
        ?TransferOrderEntity $order = null,
        array $categoryByRow = []
    ): array {
        $date = new \DateTime($movementDate);
        $sourceBatchId = (int) $sourceBatch->getId();
        $headerNotes = $this->headerNotes($dto);

        return DB::transaction(function () use (
            $dto, $sourceBatchId, $sourceBatch, $destinations, $animalsByRow, $teethByRow, $date, $headerNotes, $warnings, $order, $categoryByRow
        ) {
            // STEP 0. A sheet printed blank gets its order now, before anything moves: every
            // CACT-01 movement ends up with one, and it is recorded in step 6 like any other.
            $createdFromSheet = $this->orderExecution->needsOrderFromSheet($order, $dto);

            if ($createdFromSheet) {
                $order = $this->orderExecution->createFromSheet($dto, $destinations, $animalsByRow);
            }

            // STEP 1. Close every composition about to change, on both sides. Without
            // this the step would be drawn spread over every day since the last
            // weighing, which reads as a gradual loss instead of an instant change.
            $this->batchWeightService->snapshotBeforeMovement($sourceBatchId, $date);

            // The "before" figures are read AFTER the closing point, never from the entity
            // loaded at the start: batches.caravans_count and the averages are cached
            // columns that only recalculateBatchWeight refreshes, so a batch that changed
            // since its last recalculation would report a stale head count as its own
            // starting point — precisely the number the summary invites you to compare.
            $refreshedSource = $this->batchRepository->findById($sourceBatchId) ?? $sourceBatch;
            $before = [
                'batch_id' => $sourceBatchId,
                'batch_name' => $refreshedSource->getName(),
                'count' => $refreshedSource->getCaravansCount(),
                'average_weight' => $refreshedSource->getCurrentWeight(),
                'total_weight' => $refreshedSource->getTotalWeight(),
            ];

            foreach ($destinations as $destination) {
                if ($destination['target_batch_id'] !== null) {
                    $this->batchWeightService->snapshotBeforeMovement((int) $destination['target_batch_id'], $date);
                }
            }

            // STEP 2. Measurements, with the animals STILL in the source batch. This is
            // what makes the weighing readable as growth of the source troop instead of
            // an apparent gain of the destination.
            $weighedCount = 0;

            foreach ($animalsByRow as $index => $animal) {
                $caravanId = (int) $animal->getId();
                $row = $dto->rows[$index];

                if ($row['peso_actual'] !== null) {
                    // A weighing loaded late (a registered transfer, a sheet scanned days after)
                    // joins the history without displacing a later one as the current weight.
                    $isCurrent = !$this->caravanWeightRepository->hasWeighingAfter($caravanId, $date);

                    if ($isCurrent) {
                        $this->caravanWeightRepository->markAllNonCurrentForCaravan($caravanId);
                    }

                    $this->caravanWeightRepository->save(new CaravanWeightEntity(
                        null,
                        $caravanId,
                        $row['peso_actual'],
                        $isCurrent,
                        $date,
                        $headerNotes
                    ));
                    $weighedCount++;
                }

                // Only upwards. Dentition advances; a lower reading is a scanning error,
                // already reported as TEETH_REGRESSION and dropped from this map. This
                // rule is also what makes parseTeeth()'s default of 0 harmless.
                if (isset($teethByRow[$index]) && $teethByRow[$index] > $animal->getTeeth()) {
                    $this->caravanRepository->updateTeeth($caravanId, $teethByRow[$index]);
                }
            }

            // STEP 3. The weighing itself, but only if anything was weighed. Unlike
            // snapshotBeforeMovement(), recalculateBatchWeight() has no same-day guard,
            // so an unweighed sheet would write a CONTROL identical to the closing point
            // above: a second dot that looks like a weighing and is not one.
            if ($weighedCount > 0) {
                $this->batchWeightService->recalculateBatchWeight($sourceBatchId, BatchWeightCause::CONTROL, $date);
            }

            // STEP 4. Create the new batches, resolve each destination's RENSPA and move
            // the animals.
            $resolved = [];

            foreach ($destinations as $key => $destination) {
                if ($destination['target_batch_id'] !== null) {
                    $batch = $this->batchRepository->findById((int) $destination['target_batch_id']);
                    $created = false;
                } else {
                    $newBatch = $destination['new_batch'];
                    $batch = ($this->createBatch)(new CreateBatchDTO(
                        name: $newBatch['name'],
                        observaciones: $headerNotes,
                        activityId: $newBatch['activity_id'],
                        batchTypeId: $newBatch['batch_type_id'],
                        isConfined: $newBatch['is_confined']
                    ));
                    $created = true;
                }

                if ($batch === null) {
                    throw new DomainException('No se encontró el lote de destino después de crearlo.');
                }

                $resolved[$key] = [
                    'batch' => $batch,
                    'created' => $created,
                    'renspa' => $this->renspaOf($batch),
                    'count' => 0,
                ];
            }

            $movementIdByCaravanId = [];

            foreach ($animalsByRow as $index => $animal) {
                $row = $dto->rows[$index];
                $target = $resolved[$row['destination_key']];
                $targetBatchId = (int) $target['batch']->getId();
                $caravanId = (int) $animal->getId();

                $reclassified = $categoryByRow[$index] ?? null;
                $notes = $this->movementNotes($headerNotes, $row['observations'], $target['batch']->getName());

                if ($reclassified !== null) {
                    $this->caravanRepository->updateBatchAndReclassify(
                        $caravanId,
                        $targetBatchId,
                        (int) $reclassified['category']->getId(),
                        $reclassified['subcategory']?->getId()
                    );
                    // There is no category history: the movement is where the change is recorded.
                    $notes .= ' Categoría: ' . $this->currentCategoryLabel($animal)
                        . ' → ' . AnimalCategoryTextResolver::label($reclassified['category'], $reclassified['subcategory']) . '.';
                } else {
                    $this->caravanRepository->updateBatchAndCategory($caravanId, $targetBatchId, null);
                }

                $renspa = $target['renspa'];
                if ($renspa === '' && $animal->getCompanyId() !== null) {
                    $renspa = $this->companyRepository->findById($animal->getCompanyId())?->getRenspa() ?? '';
                }

                $movement = $this->movementRepository->save(new CaravanMovementEntity(
                    id: null,
                    caravanId: $caravanId,
                    companyId: $animal->getCompanyId(),
                    renspa: $renspa,
                    type: 'TRANSFER',
                    movementDate: $date,
                    observations: $notes,
                    fromBatchId: $animal->getBatchId(),
                    toBatchId: $targetBatchId
                ));

                $movementIdByCaravanId[$caravanId] = (int) $movement->getId();
                $resolved[$row['destination_key']]['count']++;
            }

            // STEP 5. The compositional effect, once per DISTINCT batch. Two keys that
            // resolve to the same batch must not write the same MOVEMENT_IN twice.
            $this->batchWeightService->recalculateBatchWeight($sourceBatchId, BatchWeightCause::MOVEMENT_OUT, $date);

            $seenBatchIds = [];
            foreach ($resolved as $entry) {
                $batchId = (int) $entry['batch']->getId();

                if (isset($seenBatchIds[$batchId])) {
                    continue;
                }
                $seenBatchIds[$batchId] = true;

                $this->batchWeightService->recalculateBatchWeight($batchId, BatchWeightCause::MOVEMENT_IN, $date);
            }

            // STEP 6. The order, in this same transaction: if it cannot record what was moved,
            // nothing is moved.
            // Stamped with the instant it was recorded: the movement date already lives on each
            // movement and in the history metadata.
            $orderSummary = $this->orderExecution->recordExecution($order, $dto, $movementIdByCaravanId, $resolved, new \DateTimeImmutable());

            if ($orderSummary !== null) {
                // Said apart so the screen can ask for the code to be written on the paper.
                $orderSummary['created_from_sheet'] = $createdFromSheet;
            }

            if ($orderSummary !== null && $orderSummary['pending_head_count'] > 0) {
                $warnings[] = $this->warning(
                    'TRANSFER_ORDER_PARTIAL',
                    "La orden {$orderSummary['code']} queda parcial: faltan {$orderSummary['pending_head_count']} de {$orderSummary['planned_head_count']} cabezas."
                );
            }

            $source = $this->batchRepository->findById($sourceBatchId);

            $destinationSummaries = [];
            foreach ($resolved as $entry) {
                $refreshed = $this->batchRepository->findById((int) $entry['batch']->getId());

                $destinationSummaries[] = [
                    'batch_id' => (int) $entry['batch']->getId(),
                    'batch_name' => $entry['batch']->getName(),
                    'created' => $entry['created'],
                    'count' => $entry['count'],
                    'weighed_count' => $refreshed?->getWeighedCount(),
                    'total_weight' => $refreshed?->getTotalWeight(),
                    'average_weight' => $refreshed?->getCurrentWeight(),
                ];
            }

            return [
                'source' => [
                    'before' => $before,
                    'after' => [
                        'batch_id' => $sourceBatchId,
                        'batch_name' => $source?->getName(),
                        'count' => $source?->getCaravansCount(),
                        'average_weight' => $source?->getCurrentWeight(),
                        'total_weight' => $source?->getTotalWeight(),
                    ],
                    'weighed_in_sheet' => $weighedCount,
                ],
                'destinations' => $destinationSummaries,
                'warnings' => array_values($warnings),
                'transfer_order' => $orderSummary,
            ];
        });
    }

    /**
     * @param array<int, \App\Core\Entities\ActivityEntity> $activitiesById
     * @param array<int, array{field: string, code: string, message: string}> $headerErrors
     * @param list<array{code: string, message: string}> $warnings
     * @return array<string, array<string, mixed>>
     */
    private function resolveDestinations(
        Cact01SubmissionDTO $dto,
        ?BatchEntity $sourceBatch,
        array $activitiesById,
        array &$headerErrors,
        array &$warnings
    ): array {
        $destinations = [];
        $seenBatchIds = [];
        $seenNames = [];
        $declaredManagement = $this->declaredManagement($dto->sistemaManejo);
        $managementByKey = $this->managementByDestination($dto);

        // The destination activity of the whole sheet. Every destination below is checked
        // against it: that is what makes a movement readable as one stage to another, instead
        // of a pile of batches that happened to be picked from the same list.
        $destinationActivity = $activitiesById[$dto->actividadDestinoId] ?? null;

        if ($destinationActivity === null) {
            $headerErrors[] = $this->headerError(
                'actividad_destino',
                'DESTINATION_ACTIVITY_NOT_FOUND',
                'La actividad de destino de la planilla no existe o está deshabilitada.'
            );
        }

        foreach ($dto->destinations as $destination) {
            $key = $destination['key'];

            if ($destination['target_batch_id'] !== null) {
                $batch = $this->batchRepository->findById((int) $destination['target_batch_id']);

                if ($batch === null || !$batch->isActive()) {
                    $headerErrors[] = $this->headerError('destinations', 'BATCH_NOT_FOUND', "El lote de destino de '{$key}' no existe o está cerrado.");
                    continue;
                }

                if ($sourceBatch !== null && $batch->getId() === $sourceBatch->getId()) {
                    $headerErrors[] = $this->headerError('destinations', 'SAME_BATCH', "El destino '{$key}' es el mismo lote de origen.");
                    continue;
                }

                // The invariant of the movement. A batch of another productive stage cannot
                // receive these animals, however plainly its name was written on the paper.
                if ($destinationActivity !== null
                    && (int) $batch->getActivityId() !== (int) $destinationActivity->getId()) {
                    $batchActivity = $activitiesById[$batch->getActivityId()] ?? null;
                    $headerErrors[] = $this->headerError(
                        'destinations',
                        'DESTINATION_ACTIVITY_MISMATCH',
                        "El lote '{$batch->getName()}' es de " . ($batchActivity?->getName() ?? 'otra actividad')
                        . ", pero la planilla declara destino {$destinationActivity->getName()}."
                    );
                    continue;
                }

                if (isset($seenBatchIds[(int) $batch->getId()])) {
                    $headerErrors[] = $this->headerError('destinations', 'DUPLICATED_DESTINATION', "El lote '{$batch->getName()}' figura dos veces como destino. Uní los dos grupos en uno solo.");
                    continue;
                }
                $seenBatchIds[(int) $batch->getId()] = true;

                // An existing batch is never overwritten from a sheet. What the paper says is
                // reported next to what the batch declares, and the operator decides.
                //
                // The M cells of this destination's rows outrank the header box: they are the
                // ones written about THIS batch, while the box speaks for the whole sheet.
                $writtenLetters = $managementByKey[$key] ?? [];

                if (count($writtenLetters) > 1) {
                    $warnings[] = $this->warning(
                        'MANAGEMENT_SYSTEM_CONFLICT',
                        "Las filas del lote '{$batch->getName()}' escriben corral en una y pastura en otra. No se modifica el lote: vale lo que el lote declara."
                    );
                }

                $writtenManagement = count($writtenLetters) === 1 ? $writtenLetters[0] : $declaredManagement;
                $source = count($writtenLetters) === 1 ? 'La celda M' : 'El casillero';

                if ($writtenManagement !== null) {
                    if ($batch->isConfined() === null) {
                        $warnings[] = $this->warning(
                            'MANAGEMENT_SYSTEM_UNDECLARED',
                            "El lote '{$batch->getName()}' no tiene declarado el sistema de manejo. La planilla no lo completa: cambialo desde el lote."
                        );
                    } elseif ($batch->isConfined() !== $writtenManagement) {
                        $warnings[] = $this->warning(
                            'MANAGEMENT_SYSTEM_DIFFERS',
                            "{$source} dice " . ($writtenManagement ? 'CORRAL' : 'PASTURA') . ", pero el lote '{$batch->getName()}' está declarado como " . ($batch->isConfined() ? 'corral' : 'pastura') . ". No se modifica."
                        );
                    }
                }

                $destinations[$key] = [
                    'target_batch_id' => (int) $batch->getId(),
                    'new_batch' => null,
                ];

                continue;
            }

            $newBatch = $destination['new_batch'];

            if ($newBatch === null || $newBatch['name'] === '') {
                $headerErrors[] = $this->headerError('destinations', 'BATCH_NOT_FOUND', "El destino '{$key}' no quedó resuelto: ni lote existente ni lote nuevo.");
                continue;
            }

            $normalizedName = mb_strtoupper($newBatch['name']);

            if (isset($seenNames[$normalizedName])) {
                $headerErrors[] = $this->headerError('destinations', 'DUPLICATED_DESTINATION', "Hay dos lotes nuevos con el nombre '{$newBatch['name']}'. Uní los dos grupos en uno solo.");
                continue;
            }
            $seenNames[$normalizedName] = true;

            $existingWithName = $this->batchRepository->findActiveByName($newBatch['name']);

            if ($existingWithName !== null) {
                // Two different dead ends, and telling them apart is the whole point. Inside the
                // destination activity the fix is one click: pick that batch instead. Outside it
                // the name is unusable — the batch cannot receive these animals and the name
                // cannot be reused — so somebody has to rename or point somewhere else.
                if ($destinationActivity !== null
                    && (int) $existingWithName->getActivityId() !== (int) $destinationActivity->getId()) {
                    $otherActivity = $activitiesById[$existingWithName->getActivityId()] ?? null;
                    $headerErrors[] = $this->headerError(
                        'destinations',
                        'DESTINATION_NAME_IN_OTHER_ACTIVITY',
                        "Ya existe un lote activo '{$existingWithName->getName()}' en " . ($otherActivity?->getName() ?? 'otra actividad')
                        . ", y la planilla declara destino {$destinationActivity->getName()}. Renombralo o elegí otro lote."
                    );
                    continue;
                }

                $headerErrors[] = $this->headerError('destinations', 'BATCH_NAME_IN_USE', "Ya existe un lote activo llamado '{$newBatch['name']}'. Elegilo como lote existente o cambiá el nombre.");
                continue;
            }

            $activity = $activitiesById[$newBatch['activity_id']] ?? null;

            // A batch to be created is born in the destination activity of the sheet. This used
            // to be a warning comparing the free text of the header; it is the invariant, so it
            // rejects instead of commenting, and it compares ids.
            if ($destinationActivity !== null
                && (int) $newBatch['activity_id'] !== (int) $destinationActivity->getId()) {
                $headerErrors[] = $this->headerError(
                    'destinations',
                    'NEW_BATCH_ACTIVITY_MISMATCH',
                    "El lote nuevo '{$newBatch['name']}' se crearía en " . ($activity?->getName() ?? 'otra actividad')
                    . ", pero la planilla declara destino {$destinationActivity->getName()}."
                );
                continue;
            }

            // The same batch cannot be born penned on one line and grazing on another.
            $writtenLetters = $managementByKey[$key] ?? [];

            if (count($writtenLetters) > 1) {
                $headerErrors[] = $this->headerError(
                    'destinations',
                    'MANAGEMENT_SYSTEM_CONFLICT',
                    "El lote nuevo '{$newBatch['name']}' aparece como corral en una fila y como pastura en otra. Un lote es una cosa o la otra."
                );
                continue;
            }

            // The paper answered it: a single M letter against this batch is as good a
            // declaration as the one the screen would have made.
            if ($newBatch['is_confined'] === null && count($writtenLetters) === 1) {
                $newBatch['is_confined'] = $writtenLetters[0];
            }

            // The management system belongs to every productive batch, not to Recría
            // alone. A new batch that does not declare it would be born asserting a
            // fact nobody stated.
            if ($newBatch['is_confined'] === null
                && $activity !== null
                && $activity->getCode() !== self::NON_PRODUCTIVE_ACTIVITY) {
                $headerErrors[] = $this->headerError(
                    'destinations',
                    'MANAGEMENT_SYSTEM_MISSING',
                    "Indicá si el lote nuevo '{$newBatch['name']}' se maneja a corral o de forma extensiva."
                );
                continue;
            }

            if ($activity !== null && $dto->actividadDestino !== null
                && !$this->sameActivity($dto->actividadDestino, $activity->getName(), $activity->getCode())) {
                $warnings[] = $this->warning(
                    'ACTIVITY_MISMATCH',
                    "La planilla dice que el destino es '{$dto->actividadDestino}', pero el movimiento se registra hacia {$activity->getName()}. Vale la actividad elegida en pantalla."
                );
            }

            $destinations[$key] = [
                'target_batch_id' => null,
                'new_batch' => $newBatch,
            ];
        }

        return $destinations;
    }

    /**
     * The category this row asks the animal to change to, or null when it keeps its own.
     *
     * Three sources, in this order: a pair chosen on a screen ("Registrar transferencia"), the
     * C/S cell written at the chute, and the target the order declared. What was written at the
     * chute beats the order, because that is where somebody looked at the animal; the difference
     * is reported, not hidden.
     *
     * The same category with no subcategory written is not a change: it confirms the category and
     * says nothing about the subcategory, which is kept.
     *
     * @param array<string, mixed> $row
     * @param list<array{code: string, message: string}> $errors
     * @param list<array{code: string, message: string}> $warnings
     * @return array{category: AnimalCategoryEntity, subcategory: ?AnimalSubcategoryEntity}|null
     */
    private function categoryChange(
        array $row,
        CaravanEntity $animal,
        ?TransferOrderEntity $order,
        AnimalCategoryTextResolver $resolver,
        array &$errors,
        array &$warnings
    ): ?array {
        $tag = $row['caravana'];
        $sex = $animal->getSex()->value;
        // A sheet with no order was printed blank: whatever its C/S cell says was decided there.
        $mode = $order?->getCategoryMode() ?? TransferOrderCategoryMode::AT_CHUTE;
        $line = $order?->animalByCaravanId((int) $animal->getId());
        $written = $row['cs_nueva'] ?? null;

        if (($row['category_id'] ?? null) !== null) {
            $resolution = $resolver->resolveIds((int) $row['category_id'], $row['subcategory_id'] ?? null, $sex);
            $origin = 'La categoría elegida';

            // Chosen on a screen over an order that had declared another one: the same fact the
            // sheet reports when the chute crosses out the printed C/S.
            if ($resolution->isResolved() && $line !== null && $line->hasTargetCategory()
                && ($line->getTargetCategoryId() !== $resolution->category?->getId()
                    || $line->getTargetSubcategoryId() !== $resolution->subcategory?->getId())) {
                $warnings[] = $this->warning(
                    'CATEGORY_DIFFERS_FROM_ORDER',
                    "La orden pedía '{$line->getTargetCategoryLabel()}' para '{$tag}' y se eligió '"
                    . AnimalCategoryTextResolver::label($resolution->category, $resolution->subcategory) . "'. Vale lo elegido."
                );
            }
        } elseif (!AnimalCategoryTextResolver::isBlank($written)) {
            if ($mode === TransferOrderCategoryMode::KEEP) {
                $warnings[] = $this->warning(
                    'CATEGORY_CHANGE_NOT_EXPECTED',
                    "La planilla escribe '{$written}' como categoría nueva de '{$tag}', pero la orden {$order?->getCode()} dice que la categoría no cambia. No se modifica."
                );

                return null;
            }

            $resolution = $resolver->resolve((string) $written, $sex);
            $origin = "'{$written}'";

            if ($resolution->isResolved() && $line !== null && $line->hasTargetCategory()
                && ($line->getTargetCategoryId() !== $resolution->category?->getId()
                    || $line->getTargetSubcategoryId() !== $resolution->subcategory?->getId())) {
                $warnings[] = $this->warning(
                    'CATEGORY_DIFFERS_FROM_ORDER',
                    "La orden pedía '{$line->getTargetCategoryLabel()}' para '{$tag}' y en la manga se escribió '{$written}'. Vale lo escrito en la manga."
                );
            }
        } elseif ($mode === TransferOrderCategoryMode::DECLARED && $line !== null && $line->hasTargetCategory()) {
            $resolution = $resolver->resolveIds((int) $line->getTargetCategoryId(), $line->getTargetSubcategoryId(), $sex);
            $origin = "La categoría que declara la orden ('{$line->getTargetCategoryLabel()}')";
        } else {
            return null;
        }

        if (!$resolution->isResolved()) {
            $errors[] = ($row['category_id'] ?? null) !== null && $resolution->status === AnimalCategoryResolution::NOT_FOUND
                ? $this->error('CATEGORY_NOT_FOUND', "La categoría elegida para '{$tag}' no existe, o la subcategoría no es de esa categoría.")
                : $this->categoryError($resolution, $origin, $tag, $sex);

            return null;
        }

        $category = $resolution->category;
        $subcategory = $resolution->subcategory;

        if ($category === null) {
            return null;
        }

        if ((int) $category->getId() === $animal->getCategoryId()
            && ($subcategory === null || (int) $subcategory->getId() === $animal->getSubcategoryId())) {
            return null;
        }

        return ['category' => $category, 'subcategory' => $subcategory];
    }

    /**
     * What the herd says about a movement the paper or the screen asked for. Warnings, never
     * blockers: the decision stays with whoever is moving the animals, but not in the dark.
     *
     * - A female with a recorded pregnancy that goes to finishing, to an internal activity, or to
     *   a cull subcategory. Selling pregnant cows is a valid decision; doing it unawares is not.
     * - A new category whose weight range the animal is outside of, with the weight of the day or,
     *   if it was not weighed, its current one. Only the category range: the subcategory weight is
     *   a growth target, and being below it is not being misclassified.
     *
     * @param array<int, CaravanEntity> $animalsByRow
     * @param array<int, array{category: AnimalCategoryEntity, subcategory: ?AnimalSubcategoryEntity}> $categoryByRow
     * @param array<int, \App\Core\Entities\ActivityEntity> $activitiesById
     * @return list<array{code: string, message: string}>
     */
    private function zootechnicalWarnings(Cact01SubmissionDTO $dto, array $animalsByRow, array $categoryByRow, array $activitiesById): array
    {
        $warnings = [];
        $destinationActivity = $activitiesById[$dto->actividadDestinoId] ?? null;
        $destinationCode = $destinationActivity?->getCode();
        $toCull = in_array($destinationCode, self::CULL_ACTIVITIES, true);

        foreach ($animalsByRow as $index => $animal) {
            $tag = $dto->rows[$index]['caravana'];
            $change = $categoryByRow[$index] ?? null;
            $subcategoryCode = $change !== null ? $change['subcategory']?->getCode() : $animal->getSubcategoryCode();
            $gestation = $animal->getActiveGestation();

            if ($gestation !== null && ($toCull || in_array($subcategoryCode, self::CULL_SUBCATEGORIES, true))) {
                $due = $gestation->getEstimatedDueDate();
                $warnings[] = $this->warning(
                    'PREGNANT_TO_CULL',
                    "'{$tag}' tiene preñez registrada de " . rtrim(rtrim(number_format($gestation->getGestationMonths(), 1, ',', ''), '0'), ',')
                    . ' meses' . ($due !== null ? ' (parto estimado ' . date('d/m/Y', (int) strtotime($due)) . ')' : '')
                    . ' y va ' . ($toCull ? 'a ' . ($destinationActivity?->getName() ?? 'otra actividad') : 'a descarte') . '.'
                );
            }

            if ($change === null) {
                continue;
            }

            $weight = $dto->rows[$index]['peso_actual'] ?? $animal->getCurrentWeight();
            $min = $change['category']->getMinWeightKg();
            $max = $change['category']->getMaxWeightKg();

            if ($weight !== null && (($min !== null && $weight < $min) || ($max !== null && $weight > $max))) {
                $warnings[] = $this->warning(
                    'CATEGORY_WEIGHT_OUT_OF_RANGE',
                    "'{$tag}' pasa a {$change['category']->getName()} con " . round((float) $weight) . ' kg; el rango de la categoría es '
                    . ($min !== null ? round($min) : '—') . '–' . ($max !== null ? round($max) : '—') . ' kg.'
                );
            }
        }

        return $warnings;
    }

    /**
     * @return array{code: string, message: string}
     */
    private function categoryError(AnimalCategoryResolution $resolution, string $origin, string $tag, string $sex): array
    {
        $options = implode(', ', $resolution->candidates);

        return match ($resolution->status) {
            AnimalCategoryResolution::AMBIGUOUS => $this->error(
                'CATEGORY_TEXT_AMBIGUOUS',
                "{$origin} para '{$tag}' puede ser {$options}. Elegí cuál."
            ),
            AnimalCategoryResolution::SEX_MISMATCH => $this->error(
                'CATEGORY_SEX_MISMATCH',
                "{$origin} ({$options}) no corresponde al sexo de '{$tag}' ({$sex})."
            ),
            default => $this->error(
                'CATEGORY_TEXT_NOT_FOUND',
                "{$origin} para '{$tag}' no es una categoría ni una subcategoría del catálogo."
            ),
        };
    }

    /**
     * Whether the current category written on the sheet contradicts the animal.
     *
     * Compared as a category/subcategory pair, not as text: the sheet prints the C/S label
     * ("Vaquillona / Reposición") and the animal's category name is only "Vaquillona", so a text
     * comparison flagged every animal with a subcategory. A paper that names only the category
     * says nothing about the subcategory, so it is not contradicting it.
     *
     * Text that does not resolve to one pair is compared as written, against both the name and
     * the label: the result is only ever a warning.
     */
    private function categoryDiffers(string $written, CaravanEntity $animal, AnimalCategoryTextResolver $resolver): bool
    {
        if (AnimalCategoryTextResolver::isBlank($written)) {
            return false;
        }

        $resolution = $resolver->resolve($written, $animal->getSex()->value);

        if ($resolution->isResolved() && $resolution->category !== null) {
            if ((int) $resolution->category->getId() !== $animal->getCategoryId()) {
                return true;
            }

            return $resolution->subcategory !== null
                && (int) $resolution->subcategory->getId() !== $animal->getSubcategoryId();
        }

        $text = mb_strtoupper(trim($written));

        return $text !== mb_strtoupper(trim((string) $animal->getCategoryName()))
            && $text !== mb_strtoupper($this->currentCategoryLabel($animal));
    }

    /**
     * The C/S label of what the animal is before the change, written as the sheet writes it.
     */
    private function currentCategoryLabel(CaravanEntity $animal): string
    {
        $category = $animal->getCategoryId() !== null ? $this->categoryRepository->findById($animal->getCategoryId()) : null;

        if ($category === null) {
            return 'sin categoría';
        }

        $subcategory = null;
        foreach ($category->getSubcategories() as $candidate) {
            if ((int) $candidate->getId() === $animal->getSubcategoryId()) {
                $subcategory = $candidate;
            }
        }

        return AnimalCategoryTextResolver::label($category, $subcategory);
    }

    /**
     * The distinct M letters written against each destination.
     *
     * The letter of a row describes the destination BATCH written beside it, never the animal:
     * it rides on the row only because that is where the paper has room for it. One distinct
     * value is therefore an answer about that batch, and two is a contradiction about it.
     *
     * @return array<string, list<bool>>
     */
    private function managementByDestination(Cact01SubmissionDTO $dto): array
    {
        $byKey = [];

        foreach ($dto->rows as $row) {
            if ($row['caravana'] === '' || ($row['manejo'] ?? null) === null) {
                continue;
            }

            $key = $row['destination_key'];

            if (!isset($byKey[$key])) {
                $byKey[$key] = [];
            }

            if (!in_array($row['manejo'], $byKey[$key], true)) {
                $byKey[$key][] = $row['manejo'];
            }
        }

        return $byKey;
    }

    /**
     * Whether the raw dentition cell is something the parser genuinely recognised, as
     * opposed to text it fell back to 0 on. parseTeeth() answers 0 for anything it
     * cannot read, so the only way to tell an unreadable cell apart is to ask whether
     * it carries a digit or a known alias at all.
     */
    private function looksLikeDentition(string $raw): bool
    {
        $normalized = mb_strtolower(trim($raw));

        if ($normalized === '') {
            return false;
        }

        if (preg_match('/\d/', $normalized) === 1) {
            return true;
        }

        foreach (['boca llena', 'boca_llena', 'full mouth', 'bll', 'media boca', 'media_boca', 'mb', 'leche', 'dl', 'd.l.', 'd/l', 'sin dientes', 'dos dientes', 'seis dientes'] as $alias) {
            if (str_contains($normalized, $alias)) {
                return true;
            }
        }

        return false;
    }

    private function declaredManagement(?string $sistemaManejo): ?bool
    {
        if ($sistemaManejo === null) {
            return null;
        }

        $normalized = mb_strtoupper(trim($sistemaManejo));

        if (str_contains($normalized, 'CORRAL')) {
            return true;
        }

        if (str_contains($normalized, 'PASTURA') || str_contains($normalized, 'CAMPO') || str_contains($normalized, 'EXTENSIV')) {
            return false;
        }

        return null;
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    private function sheetTotalWarnings(Cact01SubmissionDTO $dto, int $rowCount): array
    {
        $warnings = [];

        if ($dto->totalCabezas !== null && (int) $dto->totalCabezas !== $rowCount) {
            $warnings[] = $this->warning(
                'SHEET_TOTAL_MISMATCH',
                "El recuadro declara {$dto->totalCabezas} cabezas y las filas suman {$rowCount}."
            );
        }

        if ($dto->pesoTotal !== null) {
            $rowsTotal = 0.0;
            foreach ($dto->rows as $row) {
                $rowsTotal += $row['peso_actual'] ?? 0.0;
            }

            if (abs($rowsTotal - $dto->pesoTotal) > 1.0) {
                $warnings[] = $this->warning(
                    'SHEET_TOTAL_MISMATCH',
                    'El recuadro declara ' . round($dto->pesoTotal, 1) . ' kg y las filas suman ' . round($rowsTotal, 1) . ' kg.'
                );
            }
        }

        return $warnings;
    }

    /**
     * @return array<int, \App\Core\Entities\ActivityEntity>
     */
    private function activitiesById(): array
    {
        $byId = [];

        foreach ($this->activityRepository->findAll() as $activity) {
            $byId[(int) $activity->getId()] = $activity;
        }

        return $byId;
    }

    private function sameActivity(string $declared, string $name, string $code): bool
    {
        $normalized = $this->withoutAccents(mb_strtoupper(trim($declared)));

        return $normalized === $this->withoutAccents(mb_strtoupper($name))
            || $normalized === mb_strtoupper($code);
    }

    private function withoutAccents(string $value): string
    {
        return strtr($value, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
    }

    private function renspaOf(BatchEntity $batch): string
    {
        $farmId = $batch->getFarmId();

        if ($farmId === null) {
            return '';
        }

        return $this->farmRepository->findById($farmId)?->getRenspa() ?? '';
    }

    private function headerNotes(Cact01SubmissionDTO $dto): string
    {
        // The same movement reaches this use case from a scanned sheet and from the
        // transfer screen. Saying which one it was keeps the history of the animal from
        // asserting a piece of paper that never existed.
        $parts = [
            match ($dto->origin) {
                Cact01SubmissionDTO::ORIGIN_SCREEN => 'Cambio de actividad CACT-01 (orden generada desde el sistema)',
                Cact01SubmissionDTO::ORIGIN_REGISTRATION => 'Cambio de actividad CACT-01 (transferencia registrada después del hecho)',
                default => 'Cambio de actividad CACT-01 (planilla escaneada)',
            },
        ];

        if ($dto->responsable !== null) {
            $parts[] = "Responsable: {$dto->responsable}";
        }

        if ($dto->observaciones !== null) {
            $parts[] = $dto->observaciones;
        }

        return implode('. ', $parts) . '.';
    }

    private function movementNotes(string $headerNotes, ?string $rowObservations, string $targetName): string
    {
        $notes = "{$headerNotes} Destino: {$targetName}.";

        return $rowObservations !== null ? "{$notes} {$rowObservations}" : $notes;
    }

    /**
     * @return array{field: string, code: string, message: string}
     */
    private function headerError(string $field, string $code, string $message): array
    {
        return ['field' => $field, 'code' => $code, 'message' => $message];
    }

    /**
     * @return array{code: string, message: string}
     */
    private function error(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    /**
     * @return array{code: string, message: string}
     */
    private function warning(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
