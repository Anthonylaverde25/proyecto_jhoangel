<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

use App\Application\DTOs\Cact01\Cact01SubmissionDTO;
use App\Application\DTOs\CreateBatchDTO;
use App\Application\DTOs\Dest01\Dest01SubmissionDTO;
use App\Application\DTOs\WeanCaravanDTO;
use App\Application\Services\OpenOrderCommitmentChecker;
use App\Application\Services\WeaningOrderExecutionService;
use App\Application\Services\WeaningOrderRosterBuilder;
use App\Application\UseCases\Batches\CreateBatchUseCase;
use App\Application\UseCases\Caravans\WeanCaravanUseCase;
use App\Core\Entities\AnimalCategoryEntity;
use App\Core\Entities\AnimalSubcategoryEntity;
use App\Core\Entities\BatchEntity;
use App\Core\Entities\CaravanEntity;
use App\Core\Entities\WeaningOrderEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\BatchWeightCause;
use App\Core\Enums\TransferOrderCategoryMode;
use App\Core\Enums\WeaningType;
use App\Core\Exceptions\Dest01ValidationException;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\WeaningOrderDomainException;
use App\Core\Interfaces\IAnimalCategoryRepository;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanLineageRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Services\AnimalCategoryResolution;
use App\Core\Services\AnimalCategoryTextResolver;
use App\Core\Services\BatchWeightService;
use App\Core\Services\TroopWeightOutlierDetector;
use Illuminate\Support\Facades\DB;

/**
 * DEST-01: the one path that weans calves. A scanned sheet (one or several pages), an order
 * executed from the screen and a registered weaning all arrive here as the same submission, so the
 * same checks hold for all of them.
 *
 * Each calf stops nursing and moves to its weaning batch — one for everybody, or one per calf —
 * existing or new, possibly changing category, possibly weighed.
 *
 * All or nothing: every problem on the sheet is collected and reported together, and nothing is
 * persisted until the sheet is clean. Every weaning ends up with a weaning order: the one the sheet
 * names, or one created on confirming a sheet printed blank.
 */
final class ProcessDest01SubmissionUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly ICaravanLineageRepository $lineageRepository,
        private readonly IBatchRepository $batchRepository,
        private readonly IAnimalCategoryRepository $categoryRepository,
        private readonly CreateBatchUseCase $createBatch,
        private readonly WeanCaravanUseCase $weanCaravan,
        private readonly BatchWeightService $batchWeightService,
        private readonly TroopWeightOutlierDetector $outlierDetector,
        private readonly WeaningOrderRosterBuilder $roster,
        private readonly WeaningOrderExecutionService $orderExecution,
        private readonly OpenOrderCommitmentChecker $commitments
    ) {
    }

    /**
     * @return array{batch: BatchEntity, created: bool, destinations: list<array<string, mixed>>, calves_count: int, males_count: int, females_count: int, weighed_count: int, average_weight: ?float, warnings: list<array{code: string, message: string}>, weaning_order: ?array<string, mixed>}
     *
     * @throws Dest01ValidationException
     * @throws DomainException
     */
    public function __invoke(Dest01SubmissionDTO $dto): array
    {
        return $this->persist($this->validate($dto));
    }

    /**
     * Every check of the sheet, collected and thrown together.
     *
     * @return array<string, mixed>
     *
     * @throws Dest01ValidationException
     */
    private function validate(Dest01SubmissionDTO $dto): array
    {
        $headerErrors = [];
        $warnings = [];

        $weaningDate = substr($dto->fechaDestete, 0, 10);
        if ($weaningDate > (new \DateTimeImmutable('today'))->format('Y-m-d')) {
            $headerErrors[] = $this->headerError('fecha_destete', 'FUTURE_DATE', 'La fecha de destete no puede ser posterior a hoy.');
        }

        // 1. The weaning order the sheet fulfils, when it names one. Loaded before the destinations
        //    so a second round reuses the batches the first one created.
        $order = $this->orderExecution->load($dto, $headerErrors);
        $dto = $this->orderExecution->adoptResolvedDestinations($order, $dto);

        // 2. Weaning type: the order's, or what the paper marked.
        $weaningType = $this->weaningType($dto, $order, $headerErrors, $warnings);

        // 3. Destinations: each one real, a weaning batch of the destination activity, and once.
        $activityId = null;
        try {
            $activityId = $this->roster->destinationActivityId($dto->companyId);
        } catch (WeaningOrderDomainException $e) {
            $headerErrors[] = $this->headerError('lote_destete', $e->getErrorCode(), $e->getMessage());
        }

        $destinations = $this->resolveDestinations($dto, $activityId, $headerErrors);

        $declaredKeys = [];
        foreach ($dto->destinations as $destination) {
            $declaredKeys[$destination['key']] = true;
        }

        $singleKey = $dto->destinationMode === Dest01SubmissionDTO::MODE_SINGLE && count($dto->destinations) === 1
            ? $dto->destinations[0]['key']
            : null;

        // 4. Rows: resolve every calf, collecting all problems per row.
        $tags = [];
        foreach ($dto->rows as $row) {
            if ($row['caravana'] !== '') {
                $tags[] = $row['caravana'];
            }
        }
        $calvesByTag = $this->caravanRepository->findByIdentifications($tags);

        /** @var array<int, CaravanEntity> $calvesByRow */
        $calvesByRow = [];
        /** @var array<int, string> $keyByRow */
        $keyByRow = [];
        /** @var array<int, array<int, array{code: string, message: string}>> $errorsByRow */
        $errorsByRow = [];
        $seenTags = [];

        foreach ($dto->rows as $index => $row) {
            $tag = $row['caravana'];
            if ($tag === '') {
                // Blank lines of the printed table, not a missing calf.
                continue;
            }

            $upper = mb_strtoupper($tag);
            if (isset($seenTags[$upper])) {
                $errorsByRow[$index][] = $this->error('DUPLICATED_IN_SHEET', "La caravana '{$tag}' ya figura en la fila " . ($seenTags[$upper] + 1) . '.');
                continue;
            }
            $seenTags[$upper] = $index;

            // A weighing that reads zero or less is never a weight: saved, it would become the
            // calf's current weight and pull down every average it takes part in.
            if ($row['peso'] !== null && $row['peso'] <= 0) {
                $errorsByRow[$index][] = $this->error('INVALID_WEIGHT', "El peso de '{$tag}' tiene que ser mayor a cero. Corríjalo o déjelo vacío si no se pesó.");
            }

            // With one destination for everybody the row names none: the header does.
            $key = $singleKey ?? $row['destination_key'];
            if ($key === '') {
                $errorsByRow[$index][] = $this->error('DESTINATION_MISSING', "La cría '{$tag}' no tiene lote de destete: complételo en su fila.");
            } elseif (!array_key_exists($key, $destinations) && !isset($declaredKeys[$key])) {
                $errorsByRow[$index][] = $this->error('UNKNOWN_DESTINATION', "El lote '{$row['destination_key']}' de la cría '{$tag}' no está entre los lotes de destete confirmados.");
            }

            $calf = $calvesByTag[$upper] ?? null;
            if ($calf === null) {
                $errorsByRow[$index][] = $this->error('NOT_FOUND', "No existe la caravana '{$tag}'.");
                continue;
            }

            $calvesByRow[$index] = $calf;
            $keyByRow[$index] = $key;
        }

        if ($seenTags === []) {
            $headerErrors[] = $this->headerError('rows', 'NO_CALVES', 'La planilla no tiene crías cargadas.');
        }

        // 5. Lineage of every calf found, in one query, and what an open order says about it.
        $lineages = $this->lineageRepository->findByCaravanIds(
            array_values(array_map(fn (CaravanEntity $c) => (int) $c->getId(), $calvesByRow))
        );
        $committed = $this->commitments->committed(
            array_values(array_map(fn (CaravanEntity $c) => (int) $c->getId(), $calvesByRow)),
            $dto->companyId
        );

        $categoryResolver = new AnimalCategoryTextResolver($this->categoryRepository->all());
        /** @var array<int, array{category: AnimalCategoryEntity, subcategory: ?AnimalSubcategoryEntity}> $categoryByRow */
        $categoryByRow = [];

        foreach ($calvesByRow as $index => $calf) {
            $tag = $calf->getIdentification()->getValue();
            $lineage = $lineages[(int) $calf->getId()] ?? null;

            if ($lineage === null) {
                $errorsByRow[$index][] = $this->error('NO_LINEAGE', "La caravana '{$tag}' no tiene registro de nacimiento.");
                continue;
            }

            if (!$lineage->isNursing()) {
                $errorsByRow[$index][] = $this->orderExecution->alreadyWeanedByOrder($order, (int) $calf->getId(), $tag)
                    ?? $this->error('ALREADY_WEANED', "La cría '{$tag}' ya está destetada.");
            }

            $birthDate = substr($lineage->getBirthDate(), 0, 10);
            if ($birthDate !== '' && $weaningDate < $birthDate) {
                $errorsByRow[$index][] = $this->error('WEANING_BEFORE_BIRTH', "La fecha de destete es anterior al nacimiento de '{$tag}' ({$birthDate}).");
            }

            // A calf held by another open order has an answer already: weaning it here would leave
            // that order pointing at a calf that is gone.
            $holder = $committed[(int) $calf->getId()] ?? null;
            if ($holder !== null && $holder !== $order?->getCode()) {
                $errorsByRow[$index][] = $this->error('ANIMAL_IN_OPEN_ORDER', "La cría '{$tag}' está comprometida en la orden {$holder}. Ejecutala, cerrala o anulala primero.");
            }

            $categoryErrors = [];
            $change = $this->categoryChange($dto->rows[$index], $calf, $order, $categoryResolver, $categoryErrors, $warnings);

            foreach ($categoryErrors as $categoryError) {
                $errorsByRow[$index][] = $categoryError;
            }

            if ($change !== null) {
                $categoryByRow[$index] = $change;
            }
        }

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
            throw new Dest01ValidationException($headerErrors, $rowErrors);
        }

        return [
            'dto' => $dto,
            'order' => $order,
            'weaning_type' => $weaningType,
            'activity_id' => (int) $activityId,
            'destinations' => $destinations,
            'calves_by_row' => $calvesByRow,
            'key_by_row' => $keyByRow,
            'category_by_row' => $categoryByRow,
            'weaning_date' => $weaningDate,
            'warnings' => array_merge(
                $warnings,
                $this->orderExecution->rowWarnings($order, $calvesByRow),
                $this->weightWarnings($dto, $calvesByRow)
            ),
        ];
    }

    /**
     * Weans the checked sheet in one transaction: order first (a blank sheet gets one), closing
     * points of every batch about to change, new batches, the calves, one recalculation per batch,
     * and the order's record of it.
     *
     * @param array<string, mixed> $checked
     * @return array<string, mixed>
     */
    private function persist(array $checked): array
    {
        /** @var Dest01SubmissionDTO $dto */
        $dto = $checked['dto'];
        /** @var array<int, CaravanEntity> $calvesByRow */
        $calvesByRow = $checked['calves_by_row'];
        $keyByRow = $checked['key_by_row'];
        $categoryByRow = $checked['category_by_row'];
        $destinations = $checked['destinations'];
        $weaningDate = $checked['weaning_date'];
        $warnings = $checked['warnings'];
        /** @var ?WeaningOrderEntity $order */
        $order = $checked['order'];

        return DB::transaction(function () use ($dto, $calvesByRow, $keyByRow, $categoryByRow, $destinations, $weaningDate, $warnings, $order, $checked) {
            // STEP 0. A sheet printed blank gets its order now, before any calf is weaned.
            $createdFromSheet = $this->orderExecution->needsOrderFromSheet($order, $dto);

            if ($createdFromSheet) {
                $order = $this->orderExecution->createFromSheet($dto, $destinations, $calvesByRow, $keyByRow, $checked['weaning_type']);
            }

            $date = new \DateTime($weaningDate);
            $notes = $this->headerNotes($dto, $order, $checked['weaning_type']);

            // STEP 1. Close every composition about to change, on both sides, so the curves read
            // the weaning as an instant change and not as a slow drift since the last weighing.
            $sourceBatchIds = [];
            foreach ($calvesByRow as $calf) {
                if ($calf->getBatchId() !== null) {
                    $sourceBatchIds[(int) $calf->getBatchId()] = true;
                }
            }
            foreach (array_keys($sourceBatchIds) as $batchId) {
                $this->batchWeightService->snapshotBeforeMovement($batchId, $date);
            }
            foreach ($destinations as $destination) {
                if ($destination['target_batch_id'] !== null) {
                    $this->batchWeightService->snapshotBeforeMovement((int) $destination['target_batch_id'], $date);
                }
            }

            // STEP 2. Resolve each destination to a batch, creating the new ones.
            $weaningTypeId = $this->roster->weaningBatchTypeId($dto->companyId);
            $resolved = [];

            foreach ($destinations as $key => $destination) {
                if ($destination['target_batch_id'] !== null) {
                    $batch = $this->batchRepository->findById((int) $destination['target_batch_id']);
                    $created = false;
                } else {
                    $batch = ($this->createBatch)(new CreateBatchDTO(
                        name: $destination['new_batch']['name'],
                        observaciones: $notes,
                        activityId: $checked['activity_id'],
                        batchTypeId: $weaningTypeId,
                        isConfined: $destination['new_batch']['is_confined']
                    ));
                    $created = true;
                }

                if ($batch === null) {
                    throw new DomainException('No se encontró el lote de destete después de crearlo.');
                }

                $resolved[$key] = ['batch' => $batch, 'created' => $created, 'count' => 0];
            }

            // STEP 3. Wean every calf into its batch. The batch weights are recalculated once per
            // batch below, not once per calf.
            $movementIdByCaravanId = [];
            $categoryByCaravanId = [];

            foreach ($calvesByRow as $index => $calf) {
                $row = $dto->rows[$index];
                $key = $keyByRow[$index];
                $change = $categoryByRow[$index] ?? null;
                $rowNotes = $row['observations'] !== null ? "{$notes} {$row['observations']}" : $notes;

                if ($change !== null) {
                    $rowNotes .= ' Categoría: ' . $this->currentCategoryLabel($calf)
                        . ' → ' . AnimalCategoryTextResolver::label($change['category'], $change['subcategory']) . '.';
                }

                $movementIdByCaravanId[(int) $calf->getId()] = ($this->weanCaravan)(new WeanCaravanDTO(
                    caravanId: (int) $calf->getId(),
                    targetBatchId: (int) $resolved[$key]['batch']->getId(),
                    weaningDate: $weaningDate,
                    weaningWeight: $row['peso'],
                    notes: $rowNotes,
                    newCategoryId: $change !== null ? (int) $change['category']->getId() : null,
                    newSubcategoryId: $change !== null ? $change['subcategory']?->getId() : null
                ), false);

                if ($change !== null) {
                    $categoryByCaravanId[(int) $calf->getId()] = [(int) $change['category']->getId(), $change['subcategory']?->getId()];
                }

                $resolved[$key]['count']++;
            }

            // STEP 4. The compositional effect, once per distinct batch: the breeding batches lose
            // their calves, the weaning batches receive them.
            foreach (array_keys($sourceBatchIds) as $batchId) {
                $this->batchWeightService->recalculateBatchWeight($batchId, BatchWeightCause::MOVEMENT_OUT, $date);
            }

            $seen = [];
            foreach ($resolved as $entry) {
                $batchId = (int) $entry['batch']->getId();

                if (!isset($seen[$batchId]) && $entry['count'] > 0) {
                    $seen[$batchId] = true;
                    $this->batchWeightService->recalculateBatchWeight($batchId, BatchWeightCause::MOVEMENT_IN, $date);
                }
            }

            // STEP 5. The order, in this same transaction: if it cannot record what was weaned,
            // nothing is weaned.
            $orderSummary = $this->orderExecution->recordExecution(
                $order,
                $dto,
                $movementIdByCaravanId,
                $resolved,
                new \DateTimeImmutable(),
                $checked['weaning_type'],
                $categoryByCaravanId
            );

            if ($orderSummary !== null) {
                $orderSummary['created_from_sheet'] = $createdFromSheet;

                if ($orderSummary['pending_head_count'] > 0) {
                    $warnings[] = $this->warning(
                        'WEANING_ORDER_PARTIAL',
                        "La orden {$orderSummary['code']} queda parcial: faltan {$orderSummary['pending_head_count']} de {$orderSummary['planned_head_count']} crías."
                    );
                }
            }

            $destinationSummaries = [];
            foreach ($resolved as $entry) {
                $refreshed = $this->batchRepository->findById((int) $entry['batch']->getId());

                $destinationSummaries[] = [
                    'batch_id' => (int) $entry['batch']->getId(),
                    'batch_name' => $entry['batch']->getName(),
                    'created' => $entry['created'],
                    'count' => $entry['count'],
                    'average_weight' => $refreshed?->getCurrentWeight(),
                ];
            }

            $first = reset($resolved);

            return [
                'batch' => $this->batchRepository->findById((int) $first['batch']->getId()) ?? $first['batch'],
                'created' => $first['created'],
                'destinations' => $destinationSummaries,
                ...$this->summary($dto, $calvesByRow),
                'warnings' => array_values($warnings),
                'weaning_order' => $orderSummary,
            ];
        });
    }

    /**
     * @param array<int, array{field: string, code: string, message: string}> $headerErrors
     * @param list<array{code: string, message: string}> $warnings
     */
    private function weaningType(Dest01SubmissionDTO $dto, ?WeaningOrderEntity $order, array &$headerErrors, array &$warnings): ?WeaningType
    {
        $marked = WeaningType::fromText($dto->tipoDestete);

        if ($dto->tipoDestete !== null && $marked === null) {
            $headerErrors[] = $this->headerError(
                'tipo_destete',
                'WEANING_TYPE_UNKNOWN',
                "El tipo de destete marcado ('{$dto->tipoDestete}') no es uno solo de Tradicional, Anticipado o Precoz. Elegí uno o dejalo vacío."
            );

            return null;
        }

        $declared = $order?->getWeaningType();

        if ($declared !== null && $marked !== null && $declared !== $marked) {
            $warnings[] = $this->warning(
                'WEANING_TYPE_DIFFERS_FROM_ORDER',
                "La orden {$order->getCode()} declara destete {$declared->label()} y la planilla marca {$marked->label()}. Vale lo que declara la orden."
            );
        }

        return $declared ?? $marked;
    }

    /**
     * @param array<int, array{field: string, code: string, message: string}> $headerErrors
     * @return array<string, array{target_batch_id: ?int, new_batch: ?array{name: string, is_confined: ?bool}, label: string}>
     */
    private function resolveDestinations(Dest01SubmissionDTO $dto, ?int $activityId, array &$headerErrors): array
    {
        if ($dto->destinations === []) {
            $headerErrors[] = $this->headerError('lote_destete', 'BATCH_TARGET_MISSING', 'Falta indicar el lote de destete.');

            return [];
        }

        if ($dto->destinationMode === Dest01SubmissionDTO::MODE_SINGLE && count($dto->destinations) > 1) {
            $headerErrors[] = $this->headerError('lote_destete', 'SEVERAL_DESTINATIONS', 'Con destino único todas las crías van a un solo lote de destete.');

            return [];
        }

        $lettersByKey = $this->managementByDestination($dto);
        $headerManagement = Cact01SubmissionDTO::management($dto->sistemaManejo);
        $destinations = [];
        $seenBatchIds = [];
        $seenNames = [];

        foreach ($dto->destinations as $destination) {
            $key = $destination['key'];

            if ($destination['target_batch_id'] !== null) {
                $batch = $this->batchRepository->findById((int) $destination['target_batch_id']);

                if ($batch === null || !$batch->isActive() || $batch->getBatchTypeCode() !== WeaningOrderRosterBuilder::WEANING_BATCH_TYPE) {
                    $headerErrors[] = $this->headerError('lote_destete', 'BATCH_NOT_FOUND', 'El lote de destete elegido no existe, está cerrado o no es un lote de destete.');
                    continue;
                }

                if ($activityId !== null && $batch->getActivityId() !== null && (int) $batch->getActivityId() !== $activityId) {
                    $headerErrors[] = $this->headerError('lote_destete', 'DESTINATION_ACTIVITY_MISMATCH', "El lote '{$batch->getName()}' no es de la actividad de los lotes de destete.");
                    continue;
                }

                if (isset($seenBatchIds[(int) $batch->getId()])) {
                    $headerErrors[] = $this->headerError('lote_destete', 'DUPLICATED_DESTINATION', "El lote '{$batch->getName()}' figura dos veces como destino.");
                    continue;
                }
                $seenBatchIds[(int) $batch->getId()] = true;

                $destinations[$key] = ['target_batch_id' => (int) $batch->getId(), 'new_batch' => null, 'label' => $batch->getName()];
                continue;
            }

            $newBatch = $destination['new_batch'];

            if ($newBatch === null || $newBatch['name'] === '') {
                $headerErrors[] = $this->headerError('lote_destete', 'BATCH_TARGET_MISSING', 'Falta indicar el lote de destete.');
                continue;
            }

            $upperName = mb_strtoupper($newBatch['name']);
            if (isset($seenNames[$upperName])) {
                $headerErrors[] = $this->headerError('lote_destete', 'DUPLICATED_DESTINATION', "Hay dos lotes nuevos con el nombre '{$newBatch['name']}'.");
                continue;
            }
            $seenNames[$upperName] = true;

            $existingWithName = $this->batchRepository->findActiveByName($newBatch['name']);

            if ($existingWithName !== null) {
                // Only a weaning batch can be picked as the existing destination, so telling the
                // operator to pick one that is not would send them to a list where it never appears.
                $headerErrors[] = $this->headerError(
                    'lote_destete',
                    'BATCH_NAME_IN_USE',
                    $existingWithName->getBatchTypeCode() === WeaningOrderRosterBuilder::WEANING_BATCH_TYPE
                        ? "Ya existe un lote activo llamado '{$newBatch['name']}'. Elíjalo como lote existente o cambie el nombre."
                        : "Ya existe un lote activo llamado '{$newBatch['name']}', y no es un lote de destete: no puede recibir las crías ni se puede crear otro con ese nombre. Cambie el nombre o elija un lote de destete."
                );
                continue;
            }

            // The management system belongs to every productive batch. What the screen declared,
            // else the M letter of its rows, else the header box of a single-destination sheet.
            $letters = $lettersByKey[$key] ?? [];

            if (count($letters) > 1) {
                $headerErrors[] = $this->headerError('lote_destete', 'MANAGEMENT_SYSTEM_CONFLICT', "El lote nuevo '{$newBatch['name']}' aparece como corral en una fila y como pastura en otra.");
                continue;
            }

            $isConfined = $newBatch['is_confined']
                ?? ($letters[0] ?? null)
                ?? ($dto->destinationMode === Dest01SubmissionDTO::MODE_SINGLE ? $headerManagement : null);

            if ($isConfined === null) {
                $headerErrors[] = $this->headerError('lote_destete', 'MANAGEMENT_SYSTEM_MISSING', "Indicá si el lote de destete nuevo '{$newBatch['name']}' se maneja a corral o a pastura.");
                continue;
            }

            $destinations[$key] = [
                'target_batch_id' => null,
                'new_batch' => ['name' => $newBatch['name'], 'is_confined' => $isConfined],
                'label' => $newBatch['name'],
            ];
        }

        return $destinations;
    }

    /**
     * The distinct M letters written against each destination. The letter describes the batch of
     * the row, never the calf.
     *
     * @return array<string, list<bool>>
     */
    private function managementByDestination(Dest01SubmissionDTO $dto): array
    {
        $byKey = [];

        foreach ($dto->rows as $row) {
            if ($row['caravana'] === '' || $row['manejo'] === null || $row['destination_key'] === '') {
                continue;
            }

            $byKey[$row['destination_key']][$row['manejo'] ? 'C' : 'P'] = $row['manejo'];
        }

        return array_map(fn (array $letters) => array_values($letters), $byKey);
    }

    /**
     * The category this row asks the calf to change to, or null when it keeps its own.
     *
     * Three sources, in this order: a pair chosen on a screen, the C/S cell written at the chute,
     * and the target the order declared. What was written at the chute beats the order, because
     * that is where somebody looked at the calf; the difference is reported, not hidden.
     *
     * @param array<string, mixed> $row
     * @param list<array{code: string, message: string}> $errors
     * @param list<array{code: string, message: string}> $warnings
     * @return array{category: AnimalCategoryEntity, subcategory: ?AnimalSubcategoryEntity}|null
     */
    private function categoryChange(
        array $row,
        CaravanEntity $calf,
        ?WeaningOrderEntity $order,
        AnimalCategoryTextResolver $resolver,
        array &$errors,
        array &$warnings
    ): ?array {
        $tag = $row['caravana'];
        $sex = $calf->getSex()->value;
        // A sheet with no order was printed blank: whatever its C/S cell says was decided there.
        $mode = $order?->getCategoryMode() ?? TransferOrderCategoryMode::AT_CHUTE;
        $line = $order?->animalByCaravanId((int) $calf->getId());
        $written = $row['cs_nueva'] ?? null;

        if (($row['category_id'] ?? null) !== null) {
            $resolution = $resolver->resolveIds((int) $row['category_id'], $row['subcategory_id'] ?? null, $sex);
            $origin = 'La categoría elegida';

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
            $errors[] = $this->categoryError($resolution, $origin, $tag, $sex);

            return null;
        }

        $category = $resolution->category;
        $subcategory = $resolution->subcategory;

        if ($category === null) {
            return null;
        }

        // The same category, with no subcategory written, confirms it: not a change.
        if ((int) $category->getId() === $calf->getCategoryId()
            && ($subcategory === null || (int) $subcategory->getId() === $calf->getSubcategoryId())) {
            return null;
        }

        return ['category' => $category, 'subcategory' => $subcategory];
    }

    /**
     * @return array{code: string, message: string}
     */
    private function categoryError(AnimalCategoryResolution $resolution, string $origin, string $tag, string $sex): array
    {
        $options = implode(', ', $resolution->candidates);

        return match ($resolution->status) {
            AnimalCategoryResolution::AMBIGUOUS => $this->error('CATEGORY_TEXT_AMBIGUOUS', "{$origin} para '{$tag}' puede ser {$options}. Elegí cuál."),
            AnimalCategoryResolution::SEX_MISMATCH => $this->error('CATEGORY_SEX_MISMATCH', "{$origin} ({$options}) no corresponde al sexo de '{$tag}' ({$sex})."),
            default => $this->error('CATEGORY_TEXT_NOT_FOUND', "{$origin} para '{$tag}' no es una categoría ni una subcategoría del catálogo."),
        };
    }

    private function currentCategoryLabel(CaravanEntity $calf): string
    {
        $category = $calf->getCategoryId() !== null ? $this->categoryRepository->findById($calf->getCategoryId()) : null;

        if ($category === null) {
            return 'sin categoría';
        }

        $subcategory = null;
        foreach ($category->getSubcategories() as $candidate) {
            if ((int) $candidate->getId() === $calf->getSubcategoryId()) {
                $subcategory = $candidate;
            }
        }

        return AnimalCategoryTextResolver::label($category, $subcategory);
    }

    /**
     * Weights far from the rest of the troop. Advisory: the person reviewing the sheet already saw
     * them marked and confirmed; this keeps the record of it in the result.
     *
     * @param array<int, CaravanEntity> $calvesByRow
     * @return list<array{code: string, message: string}>
     */
    private function weightWarnings(Dest01SubmissionDTO $dto, array $calvesByRow): array
    {
        $weights = [];
        foreach (array_keys($calvesByRow) as $index) {
            if ($dto->rows[$index]['peso'] !== null) {
                $weights[$index] = $dto->rows[$index]['peso'];
            }
        }

        $warnings = [];
        foreach ($this->outlierDetector->outliers($weights) as $index => $outlier) {
            $warnings[] = [
                'code' => 'WEIGHT_OUTLIER',
                'message' => sprintf(
                    "El peso de '%s' (%s kg) está muy %s del resto de la tropa (mediana %s kg).",
                    $dto->rows[$index]['caravana'],
                    $this->kilos($outlier['weight']),
                    $outlier['above'] ? 'por encima' : 'por debajo',
                    $this->kilos($outlier['median'])
                ),
            ];
        }

        return $warnings;
    }

    private function kilos(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');
    }

    /**
     * @param array<int, CaravanEntity> $calvesByRow
     * @return array{calves_count: int, males_count: int, females_count: int, weighed_count: int, average_weight: ?float}
     */
    private function summary(Dest01SubmissionDTO $dto, array $calvesByRow): array
    {
        $males = 0;
        $females = 0;
        $weights = [];

        foreach ($calvesByRow as $index => $calf) {
            match ($calf->getSex()) {
                AnimalSex::MALE => $males++,
                AnimalSex::FEMALE => $females++,
                default => null,
            };

            if ($dto->rows[$index]['peso'] !== null) {
                $weights[] = $dto->rows[$index]['peso'];
            }
        }

        return [
            'calves_count' => count($calvesByRow),
            'males_count' => $males,
            'females_count' => $females,
            'weighed_count' => count($weights),
            'average_weight' => $weights === [] ? null : round(array_sum($weights) / count($weights), 1),
        ];
    }

    private function headerNotes(Dest01SubmissionDTO $dto, ?WeaningOrderEntity $order, ?WeaningType $weaningType): string
    {
        $parts = [match ($dto->origin) {
            Dest01SubmissionDTO::ORIGIN_SCREEN => 'Destete ejecutado desde la pantalla.',
            Dest01SubmissionDTO::ORIGIN_REGISTRATION => 'Destete registrado después del hecho.',
            default => 'Destete cargado desde planilla DEST-01.',
        }];

        if ($order !== null) {
            $parts[] = "Orden {$order->getCode()}.";
        }
        if ($weaningType !== null) {
            $parts[] = "Tipo: {$weaningType->label()}.";
        }
        if ($dto->loteOrigen !== null) {
            $parts[] = "Lote de origen: {$dto->loteOrigen}.";
        }
        if ($dto->responsable !== null) {
            $parts[] = "Responsable: {$dto->responsable}.";
        }
        if ($dto->observaciones !== null) {
            $parts[] = $dto->observaciones;
        }

        return implode(' ', $parts);
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

    /**
     * @return array{field: string, code: string, message: string}
     */
    private function headerError(string $field, string $code, string $message): array
    {
        return ['field' => $field, 'code' => $code, 'message' => $message];
    }
}
