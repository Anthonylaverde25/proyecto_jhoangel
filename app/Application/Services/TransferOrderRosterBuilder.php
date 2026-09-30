<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\Cact01\Cact01SubmissionDTO;
use App\Application\DTOs\TransferOrders\EmitTransferOrderDTO;
use App\Core\Entities\BatchEntity;
use App\Core\Entities\TransferOrderAnimalEntity;
use App\Core\Entities\TransferOrderDestinationEntity;
use App\Core\Enums\TransferOrderCategoryMode;
use App\Core\Exceptions\TransferOrderDomainException;
use App\Core\Interfaces\IActivityRepository;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\IBatchTypeRepository;
use App\Core\Interfaces\ITransferOrderRepository;

/**
 * Turns what the transfer screen decided into the destinations and the roll of an order, and
 * checks what an ORDER needs — which is less than what an execution needs.
 *
 * Animals may be left without a batch (it is decided at the chute), and a batch to be created
 * may leave its type or management system for the scan to ask. What cannot be left open is where
 * the animals come from, which stage they go to, and that every batch named lives there.
 *
 * Used by creating, by rewriting a draft and by issuing it, so the three agree on what a valid
 * order is.
 */
final class TransferOrderRosterBuilder
{
    public function __construct(
        private readonly ITransferOrderRepository $repository,
        private readonly IBatchRepository $batchRepository,
        private readonly IActivityRepository $activityRepository,
        private readonly IBatchTypeRepository $batchTypeRepository,
        private readonly CategoryTargetValidator $categoryTargets,
        private readonly OpenOrderCommitmentChecker $commitments
    ) {
    }

    /**
     * @throws TransferOrderDomainException
     */
    public function sourceBatch(int $sourceBatchId): BatchEntity
    {
        $batch = $this->batchRepository->findById($sourceBatchId);

        if ($batch === null || !$batch->isActive()) {
            throw TransferOrderDomainException::domainError('El lote de origen no existe o está cerrado.', 'SOURCE_BATCH_NOT_FOUND');
        }

        return $batch;
    }

    /**
     * @return array{0: TransferOrderDestinationEntity[], 1: TransferOrderAnimalEntity[]}
     *
     * @throws TransferOrderDomainException
     */
    public function build(EmitTransferOrderDTO $dto, BatchEntity $sourceBatch): array
    {
        $activityName = null;
        foreach ($this->activityRepository->findAll() as $activity) {
            if ((int) $activity->getId() === $dto->destinationActivityId) {
                $activityName = $activity->getName();
                break;
            }
        }

        if ($activityName === null) {
            throw TransferOrderDomainException::domainError('Declará la actividad de destino del movimiento.', 'DESTINATION_ACTIVITY_NOT_FOUND');
        }

        [$destinations, $orderKeyByScreenKey] = $this->destinations($dto, (int) $sourceBatch->getId(), $activityName);
        $animals = $this->animals($dto, $orderKeyByScreenKey, $this->targetCategories($dto));

        $this->assertInSourceBatch(
            array_map(fn (TransferOrderAnimalEntity $a) => $a->getCaravanId(), $animals),
            $dto->companyId,
            (int) $sourceBatch->getId(),
            $sourceBatch->getName()
        );

        return [$destinations, $animals];
    }

    /**
     * @param int[] $caravanIds
     *
     * @throws TransferOrderDomainException
     */
    public function assertInSourceBatch(array $caravanIds, int $companyId, int $sourceBatchId, string $sourceBatchName): void
    {
        $batchOf = $this->repository->currentBatchOfCaravans($caravanIds, $companyId);
        $outside = array_filter($caravanIds, fn (int $id) => ($batchOf[$id] ?? null) !== $sourceBatchId);

        if ($outside !== []) {
            throw TransferOrderDomainException::domainError(
                count($outside) . " animal(es) de la orden ya no están en el lote '{$sourceBatchName}'. Actualizá la pantalla.",
                'NOT_IN_SOURCE_BATCH'
            );
        }
    }

    /**
     * An issued order commits animals. Two open orders over the same animal would be two
     * different answers to where it goes — and that includes a weaning order holding a calf.
     * Checked when an order is issued, never for a draft.
     *
     * @param int[] $caravanIds
     *
     * @throws TransferOrderDomainException
     */
    public function assertNotCommitted(array $caravanIds, int $companyId): void
    {
        $committed = $this->commitments->committed($caravanIds, $companyId);

        if ($committed !== []) {
            throw TransferOrderDomainException::domainError(OpenOrderCommitmentChecker::message($committed), 'ANIMAL_IN_OPEN_ORDER');
        }
    }

    /**
     * @return array{0: TransferOrderDestinationEntity[], 1: array<string, string>}
     *
     * @throws TransferOrderDomainException
     */
    private function destinations(EmitTransferOrderDTO $dto, int $sourceBatchId, string $activityName): array
    {
        $destinations = [];
        $orderKeyByScreenKey = [];

        foreach ($dto->destinations as $destination) {
            $hasExisting = $destination['target_batch_id'] !== null;
            $hasNew = $destination['new_batch_name'] !== null;

            if ($hasExisting === $hasNew) {
                throw TransferOrderDomainException::domainError(
                    'Cada destino es un lote existente o uno nuevo con nombre, nunca los dos ni ninguno.',
                    'INVALID_DESTINATION'
                );
            }

            if ($hasExisting) {
                $batch = $this->batchRepository->findById((int) $destination['target_batch_id']);

                if ($batch === null || !$batch->isActive()) {
                    throw TransferOrderDomainException::domainError('Uno de los lotes de destino no existe o está cerrado.', 'BATCH_NOT_FOUND');
                }

                if ((int) $batch->getId() === $sourceBatchId) {
                    throw TransferOrderDomainException::domainError("El destino '{$batch->getName()}' es el mismo lote de origen.", 'SAME_BATCH');
                }

                if ((int) $batch->getActivityId() !== $dto->destinationActivityId) {
                    throw TransferOrderDomainException::domainError(
                        "El lote '{$batch->getName()}' no es de {$activityName}, la actividad de destino declarada.",
                        'DESTINATION_ACTIVITY_MISMATCH'
                    );
                }

                $label = $batch->getName();
            } else {
                $label = (string) $destination['new_batch_name'];

                if ($this->batchRepository->findActiveByName($label) !== null) {
                    throw TransferOrderDomainException::domainError(
                        "Ya existe un lote activo llamado '{$label}'. Elegilo como lote existente o cambiá el nombre.",
                        'BATCH_NAME_IN_USE'
                    );
                }

                if ($destination['new_batch_type_id'] !== null
                    && $this->batchTypeRepository->findById($destination['new_batch_type_id']) === null) {
                    throw TransferOrderDomainException::domainError("El tipo de lote de '{$label}' no existe.", 'BATCH_TYPE_NOT_FOUND');
                }
            }

            // The paper carries the name, so the name is the key: the same rule the scan uses to
            // join a handwritten row to its destination.
            $key = Cact01SubmissionDTO::normalizeKey($label);

            foreach ($destinations as $declared) {
                if ($declared->getKey() === $key) {
                    throw TransferOrderDomainException::domainError(
                        "Hay dos destinos que apuntan al lote '{$label}'. Uní los dos grupos en uno solo.",
                        'DUPLICATED_DESTINATION'
                    );
                }
            }

            $orderKeyByScreenKey[$destination['key']] = $key;
            $destinations[] = new TransferOrderDestinationEntity(
                id: null,
                key: $key,
                label: $label,
                targetBatchId: $hasExisting ? (int) $destination['target_batch_id'] : null,
                newBatchName: $hasNew ? $label : null,
                newBatchTypeId: $hasNew ? $destination['new_batch_type_id'] : null,
                isConfined: $hasNew ? $destination['is_confined'] : null
            );
        }

        return [$destinations, $orderKeyByScreenKey];
    }

    /**
     * The category each animal is ordered to take, checked against the catalog and its sex.
     *
     * Only a DECLARED order keeps them. In the other modes whatever the screen still holds (a
     * mode switched back, say) is dropped here instead of rejected: the mode is the decision, and
     * the leftovers never reach paper.
     *
     * @return array<int, array{0: int, 1: ?int}> caravan id => [category id, subcategory id]
     *
     * @throws TransferOrderDomainException
     */
    private function targetCategories(EmitTransferOrderDTO $dto): array
    {
        if ($dto->categoryMode !== TransferOrderCategoryMode::DECLARED) {
            return [];
        }

        $requested = array_filter(
            $dto->animals,
            fn (array $animal) => ($animal['target_category_id'] ?? null) !== null || ($animal['target_subcategory_id'] ?? null) !== null
        );

        if ($requested === []) {
            return [];
        }

        $sexOf = $this->repository->sexOfCaravans(array_column($requested, 'caravan_id'), $dto->companyId);
        ['targets' => $targets, 'invalid' => $invalid] = $this->categoryTargets->resolve(array_values($requested), $sexOf);

        if ($invalid > 0) {
            throw TransferOrderDomainException::domainError(
                "{$invalid} animal(es) tienen una categoría nueva que no existe, no corresponde a su sexo o mezcla una subcategoría de otra categoría.",
                'CATEGORY_TARGET_INVALID'
            );
        }

        return $targets;
    }

    /**
     * @param array<string, string> $orderKeyByScreenKey
     * @param array<int, array{0: int, 1: ?int}> $targetByCaravanId
     * @return TransferOrderAnimalEntity[]
     *
     * @throws TransferOrderDomainException
     */
    private function animals(EmitTransferOrderDTO $dto, array $orderKeyByScreenKey, array $targetByCaravanId): array
    {
        $caravanIds = array_values(array_unique(array_column($dto->animals, 'caravan_id')));

        if (count($caravanIds) !== count($dto->animals)) {
            throw TransferOrderDomainException::domainError('Un animal figura dos veces en la orden.', 'DUPLICATED_ANIMAL');
        }

        $animals = [];
        foreach ($dto->animals as $animal) {
            $screenKey = $animal['destination_key'];

            if ($screenKey !== null && !isset($orderKeyByScreenKey[$screenKey])) {
                throw TransferOrderDomainException::domainError('Un animal apunta a un destino que la orden no declara.', 'UNKNOWN_DESTINATION');
            }

            $animals[] = new TransferOrderAnimalEntity(
                id: null,
                caravanId: $animal['caravan_id'],
                destinationKey: $screenKey !== null ? $orderKeyByScreenKey[$screenKey] : null,
                targetCategoryId: $targetByCaravanId[$animal['caravan_id']][0] ?? null,
                targetSubcategoryId: $targetByCaravanId[$animal['caravan_id']][1] ?? null
            );
        }

        return $animals;
    }
}
