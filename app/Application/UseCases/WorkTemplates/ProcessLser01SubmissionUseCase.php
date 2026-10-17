<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

use App\Application\DTOs\Lser01\Lser01SubmissionDTO;
use App\Application\DTOs\ServiceOrders\StartBatchServiceOrderDTO;
use App\Application\UseCases\Batches\StartBatchServiceOrderUseCase;
use App\Core\Entities\BatchEntity;
use App\Core\Entities\CaravanEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\Lser01ValidationException;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Services\BullServiceFitnessPolicy;
use App\Core\Services\ServiceStartPolicy;
use App\Core\ValueObjects\CaravanNumber;

/**
 * LSER-01: service start record over an existing Cría batch.
 *
 * The sheet is printed from the batch with its females pre-listed; the operator writes the bull
 * and checks the rows at the chute. The rows are crossed against the batch: a caravan that is not
 * in the batch blocks the sheet (move it first), while a female of the batch missing from the
 * sheet is only a warning, because under natural service she shares the paddock with the bull
 * anyway and is exposed regardless.
 *
 * All or nothing: every blocking problem is reported together and nothing is persisted until the
 * sheet is clean. Persistence is delegated to StartBatchServiceOrderUseCase, the same path as
 * "Iniciar servicio" on the batch.
 */
final class ProcessLser01SubmissionUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly IBatchRepository $batchRepository,
        private readonly IServiceOrderRepository $serviceOrderRepository,
        private readonly BullServiceFitnessPolicy $fitnessPolicy,
        private readonly ServiceStartPolicy $startPolicy,
        private readonly StartBatchServiceOrderUseCase $startBatchService
    ) {
    }

    /**
     * @return array{
     *     batch: BatchEntity,
     *     service_order_id: int,
     *     service_order_code: string,
     *     females_count: int,
     *     warnings: array<int, array{code: string, caravana: string, message: string}>
     * }
     *
     * @throws Lser01ValidationException
     * @throws DomainException
     */
    public function __invoke(Lser01SubmissionDTO $dto): array
    {
        $headerErrors = [];

        // 1. Header: the batch, which must be able to enter service.
        $batch = $this->batchRepository->findById($dto->batchId);
        if ($batch === null) {
            $headerErrors[] = $this->headerError('batch_id', 'BATCH_NOT_FOUND', "No existe el lote con ID {$dto->batchId}.");
        } else {
            try {
                $this->startPolicy->assertBatchCanStartService(
                    $batch,
                    $this->serviceOrderRepository->findActiveServiceOnBatch($dto->batchId) !== null
                );
            } catch (DomainException $e) {
                $headerErrors[] = $this->headerError('batch_id', 'BATCH_NOT_ELIGIBLE', $e->getMessage());
            }
        }

        // 2. Header: the bull.
        $bull = $this->findCaravan($dto->toroCaravana);
        if ($bull === null) {
            $headerErrors[] = $this->headerError('toro_caravana', 'BULL_NOT_FOUND', "No existe la caravana de toro '{$dto->toroCaravana}'.");
        } elseif ($bull->getSex() !== AnimalSex::MALE) {
            $headerErrors[] = $this->headerError('toro_caravana', 'BULL_NOT_MALE', "La caravana '{$dto->toroCaravana}' no corresponde a un macho.");
        } else {
            try {
                $this->fitnessPolicy->assertFit((int) $bull->getId(), $dto->companyId, $dto->toroCaravana);
            } catch (DomainException $e) {
                $headerErrors[] = $this->headerError('toro_caravana', 'BULL_NOT_FIT', $e->getMessage());
            }
        }

        $bullId = $bull?->getId();
        if ($bullId !== null) {
            if ($this->serviceOrderRepository->findActiveOrdersByCaravans([$bullId], $dto->companyId) !== []) {
                $headerErrors[] = $this->headerError('toro_caravana', 'IN_ACTIVE_ORDER', "El toro '{$dto->toroCaravana}' ya está en otra orden de servicio activa.");
            }
            if ($this->caravanRepository->findIdsInExternalBatches([$bullId]) !== []) {
                $headerErrors[] = $this->headerError('toro_caravana', 'EXTERNAL_BATCH', "El toro '{$dto->toroCaravana}' está en un lote externo.");
            }
        }

        // 3. Rows: every female of the sheet, crossed against the batch.
        [$listedIds, $rowErrors] = $this->checkRows($dto);

        $hasRows = array_filter($dto->rows, fn (array $row) => $row['caravana'] !== '') !== [];
        if (!$hasRows) {
            $headerErrors[] = $this->headerError('rows', 'NO_FEMALES', 'La planilla no tiene vientres cargados.');
        }

        if ($headerErrors !== [] || $rowErrors !== []) {
            throw new Lser01ValidationException($headerErrors, $rowErrors);
        }

        // 4. Females of the batch the sheet did not list: exposed anyway under natural service.
        $warnings = array_merge(
            $this->unlistedFemalesWarnings($dto->batchId, $listedIds),
            $this->excludedFemalesWarnings($dto->batchId)
        );

        // 5. Clean sheet: the same path as "Iniciar servicio" on the batch.
        $exposedCount = count($this->caravanRepository->findServiceableFemaleIdsInBatch($dto->batchId));
        $order = ($this->startBatchService)(new StartBatchServiceOrderDTO(
            batchId: $dto->batchId,
            companyId: $dto->companyId,
            userId: $dto->userId,
            maleCaravanIds: [(int) $bullId],
            selectedFemaleCaravanIds: null,
            targetBullRatio: $exposedCount > 0 ? round(100 / $exposedCount, 2) : 3.0,
            plannedStartDate: $dto->plannedStartDate,
            plannedEndDate: $dto->plannedEndDate,
            serviceType: 'single',
            isControlledService: false,
            femaleSireAssignments: [],
            observations: $this->observations($dto)
        ));

        return [
            'batch' => $batch,
            'service_order_id' => (int) $order->getId(),
            'service_order_code' => $order->getCode(),
            'females_count' => count($order->getFemaleCaravanIds()),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{0: int[], 1: array<int, array{row_index: int, caravana: string, errors: array<int, array{code: string, message: string}>}>}
     */
    private function checkRows(Lser01SubmissionDTO $dto): array
    {
        /** @var array<int, CaravanEntity> $femalesByRow */
        $femalesByRow = [];
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

            $caravan = $this->findCaravan($tag);
            if ($caravan === null) {
                $errorsByRow[$index][] = $this->error('NOT_FOUND', "No existe la caravana '{$tag}'.");
                continue;
            }

            if ($caravan->getSex() !== AnimalSex::FEMALE) {
                $errorsByRow[$index][] = $this->error('NOT_FEMALE', "La caravana '{$tag}' no corresponde a una hembra.");
                continue;
            }

            if ($caravan->getBatchId() !== $dto->batchId) {
                $errorsByRow[$index][] = $this->error(
                    'NOT_IN_BATCH',
                    "La hembra '{$tag}' no está en el lote. Transfiérala al lote antes de iniciar el servicio."
                );
            }

            if ($caravan->isReproductiveCategory() === false) {
                $category = $caravan->getCategoryName() ?? 'de una categoría no reproductiva';
                $errorsByRow[$index][] = $this->error('NOT_BREEDING_CATEGORY', "La caravana '{$tag}' es {$category}: no entra a servicio.");
            }

            if ($caravan->hasActiveGestation()) {
                $errorsByRow[$index][] = $this->error('PREGNANT', "La hembra '{$tag}' tiene una preñez activa.");
            }

            $femalesByRow[$index] = $caravan;
        }

        $ids = array_map(fn (CaravanEntity $c) => (int) $c->getId(), $femalesByRow);
        $activeIds = array_map('intval', $this->serviceOrderRepository->findActiveOrdersByCaravans(array_values($ids), $dto->companyId));

        foreach ($femalesByRow as $index => $female) {
            if (in_array((int) $female->getId(), $activeIds, true)) {
                $tag = $female->getIdentification()->getValue();
                $errorsByRow[$index][] = $this->error('IN_ACTIVE_ORDER', "La hembra '{$tag}' ya está en otra orden de servicio activa.");
            }
        }

        ksort($errorsByRow);
        $rowErrors = [];
        foreach ($errorsByRow as $index => $errors) {
            $rowErrors[] = [
                'row_index' => $index,
                'caravana' => $dto->rows[$index]['caravana'],
                'errors' => $errors,
            ];
        }

        return [array_values($ids), $rowErrors];
    }

    /**
     * @param int[] $listedIds
     * @return array<int, array{code: string, caravana: string, message: string}>
     */
    private function unlistedFemalesWarnings(int $batchId, array $listedIds): array
    {
        $warnings = [];
        foreach ($this->caravanRepository->findServiceableFemaleIdsInBatch($batchId) as $femaleId) {
            if (in_array($femaleId, $listedIds, true)) {
                continue;
            }

            $tag = $this->caravanRepository->findById($femaleId)?->getIdentification()->getValue() ?? "#{$femaleId}";
            $warnings[] = [
                'code' => 'IN_BATCH_NOT_LISTED',
                'caravana' => $tag,
                'message' => "La hembra '{$tag}' está en el lote pero no figura en la planilla: queda igualmente en servicio.",
            ];
        }

        return $warnings;
    }

    /**
     * Females the owner excluded from breeding that are still in the batch: the sheet is a
     * natural service, so they share the paddock with the bull and are registered as exposed.
     *
     * @return array<int, array{code: string, caravana: string, message: string}>
     */
    private function excludedFemalesWarnings(int $batchId): array
    {
        $warnings = [];
        foreach ($this->caravanRepository->findBreedingExcludedFemaleIdsInBatch($batchId) as $femaleId) {
            $tag = $this->caravanRepository->findById($femaleId)?->getIdentification()->getValue() ?? "#{$femaleId}";
            $warnings[] = [
                'code' => 'BREEDING_EXCLUDED_EXPOSED',
                'caravana' => $tag,
                'message' => "La hembra '{$tag}' está excluida de reproducción pero sigue en el lote: queda expuesta al toro. Apártela si no debe servirse.",
            ];
        }

        return $warnings;
    }

    private function findCaravan(string $tag): ?CaravanEntity
    {
        if (trim($tag) === '') {
            return null;
        }

        return $this->caravanRepository->findByIdentification(new CaravanNumber($tag));
    }

    private function observations(Lser01SubmissionDTO $dto): string
    {
        $parts = ['Cargado desde planilla LSER-01.'];
        if ($dto->responsable !== null) {
            $parts[] = "Responsable: {$dto->responsable}.";
        }
        if ($dto->observaciones !== null) {
            $parts[] = $dto->observaciones;
        }

        $notes = [];
        foreach ($dto->rows as $row) {
            if ($row['caravana'] !== '' && $row['observations'] !== null) {
                $notes[] = "{$row['caravana']}: {$row['observations']}";
            }
        }
        if ($notes !== []) {
            $parts[] = 'Notas por vientre: ' . implode('; ', $notes) . '.';
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
     * @return array{field: string, code: string, message: string}
     */
    private function headerError(string $field, string $code, string $message): array
    {
        return ['field' => $field, 'code' => $code, 'message' => $message];
    }
}
