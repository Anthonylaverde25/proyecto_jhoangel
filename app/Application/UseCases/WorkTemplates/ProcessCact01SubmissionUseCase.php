<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

use App\Application\DTOs\Cact01\Cact01SubmissionDTO;
use App\Application\DTOs\CreateBatchDTO;
use App\Application\UseCases\Batches\CreateBatchUseCase;
use App\Core\Entities\BatchEntity;
use App\Core\Entities\CaravanEntity;
use App\Core\Entities\CaravanMovementEntity;
use App\Core\Entities\CaravanWeightEntity;
use App\Core\Enums\AnimalDentition;
use App\Core\Enums\BatchWeightCause;
use App\Core\Exceptions\Cact01ValidationException;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IActivityRepository;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanMovementRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\ICaravanWeightRepository;
use App\Core\Interfaces\ICompanyRepository;
use App\Core\Interfaces\IFarmRepository;
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

    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly IBatchRepository $batchRepository,
        private readonly IActivityRepository $activityRepository,
        private readonly ICaravanMovementRepository $movementRepository,
        private readonly ICaravanWeightRepository $caravanWeightRepository,
        private readonly IFarmRepository $farmRepository,
        private readonly ICompanyRepository $companyRepository,
        private readonly BatchWeightService $batchWeightService,
        private readonly CreateBatchUseCase $createBatch
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

            if ($sourceBatch !== null && $animal->getBatchId() !== $sourceBatch->getId()) {
                $errorsByRow[$index][] = $this->error(
                    'NOT_IN_SOURCE_BATCH',
                    "La caravana '{$tag}' no está en el lote '{$sourceBatch->getName()}'."
                );
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

            $animalsByRow[$index] = $animal;
        }

        if ($seenTags === []) {
            $headerErrors[] = $this->headerError('rows', 'NO_ANIMALS', 'La planilla no tiene animales cargados.');
        }

        // Advisory row-level mismatches: what the paper says about what the animal IS
        // does not overwrite the system. A difference is worth showing, not writing.
        foreach ($animalsByRow as $index => $animal) {
            $row = $dto->rows[$index];
            $tag = $row['caravana'];

            if ($row['sexo'] !== null) {
                $declared = mb_strtoupper(trim($row['sexo']))[0] ?? '';
                if ($declared !== '' && $declared !== $animal->getSex()->value) {
                    $warnings[] = $this->warning('SEX_MISMATCH', "El papel dice que '{$tag}' es {$row['sexo']}, el sistema dice {$animal->getSex()->value}. No se modifica.");
                }
            }

            if ($row['categoria'] !== null && $animal->getCategoryName() !== null
                && mb_strtoupper(trim($row['categoria'])) !== mb_strtoupper(trim($animal->getCategoryName()))) {
                $warnings[] = $this->warning('CATEGORY_MISMATCH', "El papel dice que '{$tag}' es {$row['categoria']}, el sistema dice {$animal->getCategoryName()}. No se modifica.");
            }

            if (isset($teethByRow[$index]) && $teethByRow[$index] < $animal->getTeeth()) {
                $warnings[] = $this->warning(
                    'TEETH_REGRESSION',
                    "La dentición leída para '{$tag}' ({$teethByRow[$index]}) es menor que la registrada ({$animal->getTeeth()}). La dentición avanza, así que no se modifica."
                );
                unset($teethByRow[$index]);
            }
        }

        $warnings = array_merge($warnings, $this->sheetTotalWarnings($dto, count($animalsByRow)));

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
        return $this->persist($dto, $sourceBatch, $destinations, $animalsByRow, $teethByRow, $movementDate, $warnings);
    }

    /**
     * Writes the sheet in the one order that keeps the two effects readable apart.
     *
     * @param array<string, array<string, mixed>> $destinations
     * @param array<int, CaravanEntity> $animalsByRow
     * @param array<int, int> $teethByRow
     * @param list<array{code: string, message: string}> $warnings
     * @return array{source: array<string, mixed>, destinations: list<array<string, mixed>>, warnings: list<array{code: string, message: string}>}
     */
    private function persist(
        Cact01SubmissionDTO $dto,
        BatchEntity $sourceBatch,
        array $destinations,
        array $animalsByRow,
        array $teethByRow,
        string $movementDate,
        array $warnings
    ): array {
        $date = new \DateTime($movementDate);
        $sourceBatchId = (int) $sourceBatch->getId();
        $headerNotes = $this->headerNotes($dto);

        return DB::transaction(function () use (
            $dto, $sourceBatchId, $sourceBatch, $destinations, $animalsByRow, $teethByRow, $date, $headerNotes, $warnings
        ) {
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
                    $this->caravanWeightRepository->markAllNonCurrentForCaravan($caravanId);
                    $this->caravanWeightRepository->save(new CaravanWeightEntity(
                        null,
                        $caravanId,
                        $row['peso_actual'],
                        true,
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

            foreach ($animalsByRow as $index => $animal) {
                $row = $dto->rows[$index];
                $target = $resolved[$row['destination_key']];
                $targetBatchId = (int) $target['batch']->getId();
                $caravanId = (int) $animal->getId();

                $this->caravanRepository->updateBatchAndCategory($caravanId, $targetBatchId, null);

                $renspa = $target['renspa'];
                if ($renspa === '' && $animal->getCompanyId() !== null) {
                    $renspa = $this->companyRepository->findById($animal->getCompanyId())?->getRenspa() ?? '';
                }

                $this->movementRepository->save(new CaravanMovementEntity(
                    id: null,
                    caravanId: $caravanId,
                    companyId: $animal->getCompanyId(),
                    renspa: $renspa,
                    type: 'TRANSFER',
                    movementDate: $date,
                    observations: $this->movementNotes($headerNotes, $row['observations'], $target['batch']->getName()),
                    fromBatchId: $animal->getBatchId(),
                    toBatchId: $targetBatchId
                ));

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

                if (isset($seenBatchIds[(int) $batch->getId()])) {
                    $headerErrors[] = $this->headerError('destinations', 'DUPLICATED_DESTINATION', "El lote '{$batch->getName()}' figura dos veces como destino. Uní los dos grupos en uno solo.");
                    continue;
                }
                $seenBatchIds[(int) $batch->getId()] = true;

                // An existing batch is never overwritten from a sheet. What the box says
                // is reported next to what the batch declares, and the operator decides.
                if ($declaredManagement !== null) {
                    if ($batch->isConfined() === null) {
                        $warnings[] = $this->warning(
                            'MANAGEMENT_SYSTEM_UNDECLARED',
                            "El lote '{$batch->getName()}' no tiene declarado el sistema de manejo. La planilla no lo completa: cambialo desde el lote."
                        );
                    } elseif ($batch->isConfined() !== $declaredManagement) {
                        $warnings[] = $this->warning(
                            'MANAGEMENT_SYSTEM_DIFFERS',
                            "El casillero dice " . ($declaredManagement ? 'CORRAL' : 'PASTURA') . ", pero el lote '{$batch->getName()}' está declarado como " . ($batch->isConfined() ? 'corral' : 'pastura') . ". No se modifica."
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

            if ($this->batchRepository->findActiveByName($newBatch['name']) !== null) {
                $headerErrors[] = $this->headerError('destinations', 'BATCH_NAME_IN_USE', "Ya existe un lote activo llamado '{$newBatch['name']}'. Elegilo como lote existente o cambiá el nombre.");
                continue;
            }

            // The management system belongs to every productive batch, not to Recría
            // alone. A new batch that does not declare it would be born asserting a
            // fact nobody stated.
            $activity = $activitiesById[$newBatch['activity_id']] ?? null;

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
                    "La planilla dice que el destino es '{$dto->actividadDestino}', pero el lote '{$newBatch['name']}' se crea en {$activity->getName()}."
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
        $parts = ['Cambio de actividad CACT-01'];

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
