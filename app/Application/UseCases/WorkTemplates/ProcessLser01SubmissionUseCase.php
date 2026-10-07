<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

use App\Application\DTOs\CreateServiceBatchDTO;
use App\Application\DTOs\Lser01\Lser01SubmissionDTO;
use App\Application\UseCases\Batches\CreateServiceBatchUseCase;
use App\Core\Entities\BatchEntity;
use App\Core\Entities\CaravanEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\Lser01ValidationException;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Services\BullServiceFitnessPolicy;
use App\Core\ValueObjects\CaravanNumber;
use App\Models\ServiceOrder;

/**
 * LSER-01: turns a hand-filled single-bull service batch sheet into a service batch, its
 * service order and the livestock movements.
 *
 * All or nothing: every problem on the sheet is collected and reported together, and nothing
 * is persisted until the sheet is clean. Persistence is delegated to CreateServiceBatchUseCase,
 * the same path the wizard uses, which re-checks admission and bull fitness on its own.
 */
final class ProcessLser01SubmissionUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly IServiceOrderRepository $serviceOrderRepository,
        private readonly BullServiceFitnessPolicy $fitnessPolicy,
        private readonly CreateServiceBatchUseCase $createServiceBatch
    ) {
    }

    /**
     * @return array{batch: BatchEntity, service_order_id: ?int, service_order_code: ?string, females_count: int}
     *
     * @throws Lser01ValidationException
     * @throws DomainException
     */
    public function __invoke(Lser01SubmissionDTO $dto): array
    {
        $headerErrors = [];
        $rowErrors = [];

        // 1. Header: the bull.
        $bull = $this->findCaravan($dto->toroCaravana);
        if ($bull === null) {
            $headerErrors[] = $this->headerError('BULL_NOT_FOUND', "No existe la caravana de toro '{$dto->toroCaravana}'.");
        } elseif ($bull->getSex() !== AnimalSex::MALE) {
            $headerErrors[] = $this->headerError('BULL_NOT_MALE', "La caravana '{$dto->toroCaravana}' no corresponde a un macho.");
        } elseif ($bull->getCategoryId() === null) {
            $headerErrors[] = $this->headerError('BULL_WITHOUT_CATEGORY', "El toro '{$dto->toroCaravana}' no tiene categoría registrada.");
        } else {
            try {
                $this->fitnessPolicy->assertFit((int) $bull->getId(), $dto->companyId, $dto->toroCaravana);
            } catch (DomainException $e) {
                $headerErrors[] = $this->headerError('BULL_NOT_FIT', $e->getMessage());
            }
        }

        // 2. Rows: resolve every female, collecting all problems per row.
        /** @var array<int, CaravanEntity> $femalesByRow */
        $femalesByRow = [];
        /** @var array<int, array<int, array{code: string, message: string}>> $errorsByRow */
        $errorsByRow = [];
        $seenTags = [];
        $hasRows = false;

        foreach ($dto->rows as $index => $row) {
            $tag = $row['caravana'];
            if ($tag === '') {
                // Blank lines of the printed table, not a missing animal.
                continue;
            }
            $hasRows = true;

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

            if ($caravan->getCategoryId() === null) {
                $errorsByRow[$index][] = $this->error('WITHOUT_CATEGORY', "La hembra '{$tag}' no tiene categoría registrada.");
            }

            if ($caravan->hasActiveGestation()) {
                $errorsByRow[$index][] = $this->error('PREGNANT', "La hembra '{$tag}' tiene una preñez activa.");
            }

            $femalesByRow[$index] = $caravan;
        }

        if (!$hasRows) {
            $headerErrors[] = [
                'field' => 'rows',
                'code' => 'NO_FEMALES',
                'message' => 'La planilla no tiene vientres cargados.',
            ];
        }

        // 3. The batch category is the one most females share; the rest do not belong here.
        [$femaleCategoryId, $femaleCategoryName] = $this->dominantCategory($femalesByRow);
        foreach ($femalesByRow as $index => $female) {
            if ($female->getCategoryId() !== null && $female->getCategoryId() !== $femaleCategoryId) {
                $tag = $female->getIdentification()->getValue();
                $errorsByRow[$index][] = $this->error(
                    'CATEGORY_MISMATCH',
                    "La hembra '{$tag}' es {$female->getCategoryName()}, y el lote es de {$femaleCategoryName}."
                );
            }
        }

        // 4. Animals already committed elsewhere.
        $idsToCheck = array_map(fn (CaravanEntity $c) => (int) $c->getId(), $femalesByRow);
        $bullId = $bull?->getId();

        $activeIds = array_map('intval', $this->serviceOrderRepository->findActiveOrdersByCaravans(
            array_values(array_filter([...$idsToCheck, $bullId])),
            $dto->companyId
        ));
        $externalIds = $this->caravanRepository->findIdsInExternalBatches(
            array_values(array_filter([...$idsToCheck, $bullId]))
        );

        if ($bullId !== null && in_array($bullId, $activeIds, true)) {
            $headerErrors[] = $this->headerError('IN_ACTIVE_ORDER', "El toro '{$dto->toroCaravana}' ya está en otra orden de servicio activa.");
        }
        if ($bullId !== null && in_array($bullId, $externalIds, true)) {
            $headerErrors[] = $this->headerError('EXTERNAL_BATCH', "El toro '{$dto->toroCaravana}' está en un lote externo.");
        }

        foreach ($femalesByRow as $index => $female) {
            $tag = $female->getIdentification()->getValue();
            if (in_array((int) $female->getId(), $activeIds, true)) {
                $errorsByRow[$index][] = $this->error('IN_ACTIVE_ORDER', "La hembra '{$tag}' ya está en otra orden de servicio activa.");
            }
            if (in_array((int) $female->getId(), $externalIds, true)) {
                $errorsByRow[$index][] = $this->error('EXTERNAL_BATCH', "La hembra '{$tag}' está en un lote externo.");
            }
        }

        ksort($errorsByRow);
        foreach ($errorsByRow as $index => $errors) {
            $rowErrors[] = [
                'row_index' => $index,
                'caravana' => $dto->rows[$index]['caravana'],
                'errors' => $errors,
            ];
        }

        if (!empty($headerErrors) || !empty($rowErrors)) {
            throw new Lser01ValidationException($headerErrors, $rowErrors);
        }

        // 5. Clean sheet: delegate persistence to the wizard's use case.
        $females = array_values($femalesByRow);
        $batch = ($this->createServiceBatch)(new CreateServiceBatchDTO(
            name: $dto->lote,
            femaleCategoryId: (int) $femaleCategoryId,
            maleCategoryId: (int) $bull->getCategoryId(),
            femaleSubcategoryId: $this->commonSubcategory($females),
            femaleCaravanIds: array_map(fn (CaravanEntity $c) => (int) $c->getId(), $females),
            maleCaravanIds: [(int) $bullId],
            farmId: null,
            targetBullRatio: round(100 / count($females), 2),
            plannedStartDate: $dto->plannedStartDate,
            plannedEndDate: $dto->plannedEndDate,
            notes: $this->rowNotes($dto),
            observaciones: $this->observations($dto),
            autoCreateServiceOrder: true
        ));

        $order = ServiceOrder::where('batch_id', $batch->getId())->latest('id')->first();

        return [
            'batch' => $batch,
            'service_order_id' => $order?->id,
            'service_order_code' => $order?->code,
            'females_count' => count($females),
        ];
    }

    private function findCaravan(string $tag): ?CaravanEntity
    {
        if (trim($tag) === '') {
            return null;
        }

        return $this->caravanRepository->findByIdentification(new CaravanNumber($tag));
    }

    /**
     * @param array<int, CaravanEntity> $females
     * @return array{0: ?int, 1: ?string}
     */
    private function dominantCategory(array $females): array
    {
        $counts = [];
        $names = [];
        foreach ($females as $female) {
            $id = $female->getCategoryId();
            if ($id === null) {
                continue;
            }
            $counts[$id] = ($counts[$id] ?? 0) + 1;
            $names[$id] = $female->getCategoryName();
        }

        if (empty($counts)) {
            return [null, null];
        }

        arsort($counts);
        $id = (int) array_key_first($counts);

        return [$id, $names[$id]];
    }

    /**
     * @param CaravanEntity[] $females
     */
    private function commonSubcategory(array $females): ?int
    {
        $subcategories = array_unique(array_map(fn (CaravanEntity $c) => $c->getSubcategoryId(), $females));

        return count($subcategories) === 1 ? reset($subcategories) : null;
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

        return implode(' ', $parts);
    }

    private function rowNotes(Lser01SubmissionDTO $dto): ?string
    {
        $notes = [];
        foreach ($dto->rows as $row) {
            if ($row['caravana'] !== '' && $row['observations'] !== null) {
                $notes[] = "{$row['caravana']}: {$row['observations']}";
            }
        }

        return empty($notes) ? null : implode("\n", $notes);
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
    private function headerError(string $code, string $message): array
    {
        return ['field' => 'toro_caravana', 'code' => $code, 'message' => $message];
    }
}
