<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\Cact01\Cact01SubmissionDTO;
use App\Application\DTOs\WeaningOrders\EmitWeaningOrderDTO;
use App\Core\Entities\WeaningOrderAnimalEntity;
use App\Core\Entities\WeaningOrderDestinationEntity;
use App\Core\Enums\TransferOrderCategoryMode;
use App\Core\Exceptions\WeaningOrderDomainException;
use App\Core\Interfaces\IActivityRepository;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\IBatchTypeRepository;
use App\Core\Interfaces\IWeaningOrderRepository;

/**
 * Turns what the weaning screen decided into the destinations and the roll of an order, and
 * checks what an ORDER needs — which is less than what an execution needs.
 *
 * A calf may be left without a weaning batch (it is decided at the chute), and a batch to be
 * created may leave its management system for the scan to ask. What cannot be left open is that
 * every calf is a nursing calf of the company, and that every batch named is a weaning batch of
 * the destination activity.
 *
 * Used by creating, by rewriting a draft, by issuing it and by registering, so all of them agree on
 * what a valid weaning order is.
 */
final class WeaningOrderRosterBuilder
{
    public const WEANING_BATCH_TYPE = 'WEANING';
    private const FALLBACK_ACTIVITY = 'CRIA';

    public function __construct(
        private readonly IWeaningOrderRepository $repository,
        private readonly IBatchRepository $batchRepository,
        private readonly IBatchTypeRepository $batchTypeRepository,
        private readonly IActivityRepository $activityRepository,
        private readonly CategoryTargetValidator $categoryTargets,
        private readonly OpenOrderCommitmentChecker $commitments
    ) {
    }

    /**
     * The stage weaned calves go to: the activity the WEANING batch type belongs to. Nobody
     * chooses it — it is the one stage a weaning batch can be of — but the order declares it, and
     * every destination is checked against it.
     *
     * @throws WeaningOrderDomainException
     */
    public function destinationActivityId(int $companyId): int
    {
        $type = $this->batchTypeRepository->findByCodeAndCompany(self::WEANING_BATCH_TYPE, $companyId);

        if ($type === null) {
            throw WeaningOrderDomainException::domainError('El tipo de lote Destete no está habilitado para la empresa.', 'WEANING_BATCH_TYPE_MISSING');
        }

        $activityId = $type->getActivityId() ?? $this->activityRepository->findByCode(self::FALLBACK_ACTIVITY)?->getId();

        if ($activityId === null) {
            throw WeaningOrderDomainException::domainError('No se encontró la actividad de los lotes de destete.', 'DESTINATION_ACTIVITY_NOT_FOUND');
        }

        return (int) $activityId;
    }

    public function weaningBatchTypeId(int $companyId): ?int
    {
        return $this->batchTypeRepository->findByCodeAndCompany(self::WEANING_BATCH_TYPE, $companyId)?->getId();
    }

    /**
     * @return array{0: WeaningOrderDestinationEntity[], 1: WeaningOrderAnimalEntity[]}
     *
     * @throws WeaningOrderDomainException
     */
    public function build(EmitWeaningOrderDTO $dto, int $destinationActivityId): array
    {
        [$destinations, $orderKeyByScreenKey] = $this->destinations($dto, $destinationActivityId);
        $facts = $this->assertNursingCalves(array_column($dto->animals, 'caravan_id'), $dto->companyId);
        $animals = $this->animals($dto, $orderKeyByScreenKey, $this->targetCategories($dto, $facts), $facts);

        return [$destinations, $animals];
    }

    /**
     * Every calf exists in the company, has a birth record and is still at foot. Checked when the
     * order is written and again when a draft is issued: a draft may be days old.
     *
     * @param int[] $caravanIds
     * @return array<int, array{identification: string, batch_id: ?int, sex: string, is_nursing: ?bool, birth_date: ?string}>
     *
     * @throws WeaningOrderDomainException
     */
    public function assertNursingCalves(array $caravanIds, int $companyId): array
    {
        $caravanIds = array_values(array_unique($caravanIds));

        if (count($caravanIds) === 0) {
            throw WeaningOrderDomainException::domainError('Elegí al menos una cría.', 'EMPTY_ROLL');
        }

        $facts = $this->repository->calfFacts($caravanIds, $companyId);
        $missing = array_filter($caravanIds, fn (int $id) => !isset($facts[$id]));

        if ($missing !== []) {
            throw WeaningOrderDomainException::domainError(count($missing) . ' cría(s) de la orden no existen en la empresa.', 'CALF_NOT_FOUND');
        }

        $withoutBirth = array_filter($facts, fn (array $fact) => $fact['is_nursing'] === null);

        if ($withoutBirth !== []) {
            throw WeaningOrderDomainException::domainError(
                'Sin registro de nacimiento: ' . implode(', ', array_column($withoutBirth, 'identification')) . '. Sólo se desteta una cría con su parto registrado.',
                'NO_LINEAGE'
            );
        }

        $weaned = array_filter($facts, fn (array $fact) => $fact['is_nursing'] === false);

        if ($weaned !== []) {
            throw WeaningOrderDomainException::domainError(
                'Ya destetadas: ' . implode(', ', array_column($weaned, 'identification')) . '.',
                'ALREADY_WEANED'
            );
        }

        return $facts;
    }

    /**
     * An issued order commits calves. A calf pending in another open order — of weaning or of
     * transfer — would get two different answers to where it goes.
     *
     * @param int[] $caravanIds
     *
     * @throws WeaningOrderDomainException
     */
    public function assertNotCommitted(array $caravanIds, int $companyId): void
    {
        $committed = $this->commitments->committed($caravanIds, $companyId);

        if ($committed !== []) {
            throw WeaningOrderDomainException::domainError(OpenOrderCommitmentChecker::message($committed, 'cría(s)'), 'ANIMAL_IN_OPEN_ORDER');
        }
    }

    /**
     * @return array{0: WeaningOrderDestinationEntity[], 1: array<string, string>}
     *
     * @throws WeaningOrderDomainException
     */
    private function destinations(EmitWeaningOrderDTO $dto, int $destinationActivityId): array
    {
        $destinations = [];
        $orderKeyByScreenKey = [];

        foreach ($dto->destinations as $destination) {
            $hasExisting = $destination['target_batch_id'] !== null;
            $hasNew = $destination['new_batch_name'] !== null;

            if ($hasExisting === $hasNew) {
                throw WeaningOrderDomainException::domainError(
                    'Cada destino es un lote de destete existente o uno nuevo con nombre, nunca los dos ni ninguno.',
                    'INVALID_DESTINATION'
                );
            }

            if ($hasExisting) {
                $batch = $this->batchRepository->findById((int) $destination['target_batch_id']);

                if ($batch === null || !$batch->isActive()) {
                    throw WeaningOrderDomainException::domainError('Uno de los lotes de destete no existe o está cerrado.', 'BATCH_NOT_FOUND');
                }

                if ($batch->getBatchTypeCode() !== self::WEANING_BATCH_TYPE) {
                    throw WeaningOrderDomainException::domainError("El lote '{$batch->getName()}' no es un lote de destete.", 'NOT_A_WEANING_BATCH');
                }

                // A weaning batch from before batch types had an activity has none: it is still a weaning batch.
                if ($batch->getActivityId() !== null && (int) $batch->getActivityId() !== $destinationActivityId) {
                    throw WeaningOrderDomainException::domainError(
                        "El lote '{$batch->getName()}' no es de la actividad de los lotes de destete.",
                        'DESTINATION_ACTIVITY_MISMATCH'
                    );
                }

                $label = $batch->getName();
            } else {
                $label = (string) $destination['new_batch_name'];
                // Only another weaning batch blocks the name: one of another type can never be the
                // destination, so sharing its name is advised against, not forbidden.
                foreach ($this->batchRepository->findAllActiveByName($label) as $existing) {
                    if ($existing->getBatchTypeCode() === self::WEANING_BATCH_TYPE) {
                        throw WeaningOrderDomainException::domainError(
                            "Ya existe un lote de destete activo llamado '{$label}'. Elegilo como lote existente o cambiá el nombre.",
                            'BATCH_NAME_IN_USE'
                        );
                    }
                }
            }

            // The paper carries the name, so the name is the key: the same rule the scan uses to
            // join a handwritten row to its destination.
            $key = Cact01SubmissionDTO::normalizeKey($label);

            foreach ($destinations as $declared) {
                if ($declared->getKey() === $key) {
                    throw WeaningOrderDomainException::domainError(
                        "Hay dos destinos que apuntan al lote '{$label}'. Uní los dos grupos en uno solo.",
                        'DUPLICATED_DESTINATION'
                    );
                }
            }

            $orderKeyByScreenKey[$destination['key']] = $key;
            $destinations[] = new WeaningOrderDestinationEntity(
                id: null,
                key: $key,
                label: $label,
                targetBatchId: $hasExisting ? (int) $destination['target_batch_id'] : null,
                newBatchName: $hasNew ? $label : null,
                isConfined: $hasNew ? $destination['is_confined'] : null
            );
        }

        return [$destinations, $orderKeyByScreenKey];
    }

    /**
     * The category each calf is ordered to take. Only a DECLARED order keeps them; in the other
     * modes whatever the screen still holds is dropped, because the mode is the decision.
     *
     * @param array<int, array{identification: string, batch_id: ?int, sex: string, is_nursing: ?bool, birth_date: ?string}> $facts
     * @return array<int, array{0: int, 1: ?int}>
     *
     * @throws WeaningOrderDomainException
     */
    private function targetCategories(EmitWeaningOrderDTO $dto, array $facts): array
    {
        if ($dto->categoryMode !== TransferOrderCategoryMode::DECLARED) {
            return [];
        }

        $requested = array_values(array_filter(
            $dto->animals,
            fn (array $animal) => $animal['target_category_id'] !== null || $animal['target_subcategory_id'] !== null
        ));

        $sexOf = array_map(fn (array $fact) => $fact['sex'], $facts);
        ['targets' => $targets, 'invalid' => $invalid] = $this->categoryTargets->resolve($requested, $sexOf);

        if ($invalid > 0) {
            throw WeaningOrderDomainException::domainError(
                "{$invalid} cría(s) tienen una categoría nueva que no existe, no corresponde a su sexo o mezcla una subcategoría de otra categoría.",
                'CATEGORY_TARGET_INVALID'
            );
        }

        return $targets;
    }

    /**
     * @param array<string, string> $orderKeyByScreenKey
     * @param array<int, array{0: int, 1: ?int}> $targetByCaravanId
     * @param array<int, array{identification: string, batch_id: ?int, sex: string, is_nursing: ?bool, birth_date: ?string}> $facts
     * @return WeaningOrderAnimalEntity[]
     *
     * @throws WeaningOrderDomainException
     */
    private function animals(EmitWeaningOrderDTO $dto, array $orderKeyByScreenKey, array $targetByCaravanId, array $facts): array
    {
        $caravanIds = array_values(array_unique(array_column($dto->animals, 'caravan_id')));

        if (count($caravanIds) !== count($dto->animals)) {
            throw WeaningOrderDomainException::domainError('Una cría figura dos veces en la orden.', 'DUPLICATED_ANIMAL');
        }

        $animals = [];
        foreach ($dto->animals as $animal) {
            $screenKey = $animal['destination_key'];

            // With one destination for everybody, the screen may leave the calves pointing nowhere:
            // the header names the batch they all go to.
            if ($screenKey === null && $dto->destinationMode === 'single' && count($orderKeyByScreenKey) === 1) {
                $screenKey = (string) array_key_first($orderKeyByScreenKey);
            }

            if ($screenKey !== null && !isset($orderKeyByScreenKey[$screenKey])) {
                throw WeaningOrderDomainException::domainError('Una cría apunta a un lote de destete que la orden no declara.', 'UNKNOWN_DESTINATION');
            }

            $animals[] = new WeaningOrderAnimalEntity(
                id: null,
                caravanId: $animal['caravan_id'],
                sourceBatchId: $facts[$animal['caravan_id']]['batch_id'] ?? null,
                destinationKey: $screenKey !== null ? $orderKeyByScreenKey[$screenKey] : null,
                targetCategoryId: $targetByCaravanId[$animal['caravan_id']][0] ?? null,
                targetSubcategoryId: $targetByCaravanId[$animal['caravan_id']][1] ?? null
            );
        }

        return $animals;
    }
}
