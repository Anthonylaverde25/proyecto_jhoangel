<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\FemaleReproductiveVerdict;
use App\Core\Enums\NotDiagnosedReason;
use App\Core\Enums\ReproductiveAptitudeStatus;
use App\Core\Enums\ServiceExposureExitReason;

interface IServiceOrderRepository
{
    /**
     * Save/persist a service order.
     */
    public function save(ServiceOrderEntity $entity, int $actionUserId, ?string $reason = null): ServiceOrderEntity;

    /**
     * Find a service order by its ID and tenant company ID.
     */
    public function findById(int $id, int $companyId): ?ServiceOrderEntity;

    /**
     * Check if any of the given caravans are currently involved in an active service order.
     * Active orders are in status DRAFT or APPROVED; a female counts only while her exposure
     * is open (one that left the serviced batch is free to join another service).
     * Returns an array of conflicting caravan IDs.
     *
     * @param int[] $caravanIds
     * @return int[]
     */
    public function findActiveOrdersByCaravans(array $caravanIds, int $companyId): array;

    /**
     * Verify if animals exist, belong to the company, and match the specified sex.
     * Returns a map of ID => Sex.
     *
     * @param int[] $caravanIds
     * @return array<int, string>
     */
    public function verifyAnimalsSexAndOwnership(array $caravanIds, int $companyId): array;

    /**
     * Move the bulls of a service into the serviced batch and log one movement per bull.
     * Females never move: they already are in the batch.
     *
     * @param int[] $bullIds
     * @return int[] IDs of the batches the bulls left (their aggregates must be recalculated).
     */
    public function moveBullsToBatch(array $bullIds, int $batchId, int $companyId, string $movementDate, string $orderCode): array;

    /**
     * The last code starting with the prefix, read with a row lock so concurrent
     * creations cannot reuse a sequence. Must run inside a transaction.
     */
    public function lockLastCodeWithPrefix(int $companyId, string $prefix): ?string;

    /**
     * A planned order: no animal moves and no exposure opens until it starts.
     *
     * @param array<string, mixed> $attributes columns of the order
     * @param int[] $femaleIds
     * @param int[] $maleIds
     * @param array<int, int> $plannedSemen female_caravan_id => semen item id
     */
    public function createPlanned(array $attributes, array $femaleIds, array $maleIds, array $plannedSemen, string $reason): int;

    /**
     * Changes what a planned order says; its bulls are replaced by the given ones.
     *
     * @param array<string, mixed> $attributes columns of the order
     * @param int[] $maleIds
     */
    public function updatePlanned(int $orderId, int $companyId, array $attributes, array $maleIds, int $userId): void;

    /**
     * An order that ended in the field, born ended with its real dates.
     *
     * @param array<string, mixed> $attributes columns of the order
     * @param int[] $femaleIds
     * @param list<array{male_caravan_id: int, joined_at: string, retired_at: string}> $bulls
     */
    public function createRegistered(array $attributes, array $femaleIds, array $bulls, ServiceExposureExitReason $exitReason, int $userId, string $reason): int;

    /**
     * Code of an order of the batch that ran on these days, if any. Touching on one day is no overlap.
     */
    public function findOverlappingOrderOnBatch(int $batchId, int $companyId, string $from, string $until): ?string;

    /**
     * Females served in another order on these days.
     *
     * @param int[] $femaleIds
     * @return list<array{identification: string, code: string}>
     */
    public function findOverlappingFemales(array $femaleIds, int $companyId, string $from, string $until): array;

    /**
     * Code of an order where the bull served on these days, if any. Leaving a batch and joining
     * another on the same day is no overlap.
     */
    public function findOverlappingBullOrder(int $bullId, int $companyId, string $from, string $until): ?string;

    /**
     * Females among these with a current gestation.
     *
     * @param int[] $femaleIds
     * @return string[] their identifications
     */
    public function findFemalesWithCurrentGestation(array $femaleIds, int $companyId): array;

    /**
     * Batches of the company among these.
     *
     * @param int[] $batchIds
     * @return int[]
     */
    public function findCompanyBatchIds(array $batchIds, int $companyId): array;

    /**
     * Where each bull was on the given day according to its movements, and whether it moved after
     * another day.
     *
     * @param int[] $bullIds
     * @return list<array{caravan_id: int, batch_id: ?int, current_batch_id: ?int, moved_after: bool}>
     */
    public function findBullLocations(array $bullIds, int $companyId, string $onDay, string $movedAfterDay): array;

    /**
     * A dated movement of a bull, between batches declared by the operator. It does not change the
     * batch the bull is in today.
     */
    public function recordBullMovement(int $bullId, int $companyId, int $fromBatchId, int $toBatchId, string $date, string $observations): void;

    /**
     * Puts the bull in the batch.
     *
     * @return ?int the batch it was in, or null when it did not change
     */
    public function relocateBull(int $bullId, int $companyId, int $batchId): ?int;

    /**
     * The active (DRAFT or APPROVED) service running on a batch, as a lightweight reference.
     *
     * @return array{id: int, status: string, is_controlled_service: bool, service_method: string}|null
     */
    public function findActiveServiceOnBatch(int $batchId): ?array;

    /**
     * The order still open on a batch: running, or over and waiting for its diagnosis. The oldest
     * one, because it is the first that has to be closed.
     *
     * @param int|null $exceptOrderId the order asking, when it is itself a draft
     * @return array{id: int, code: string, status: string}|null
     */
    public function findUnclosedOrderOnBatch(int $batchId, ?int $exceptOrderId = null): ?array;

    /**
     * The orders in service or waiting for their diagnosis that hold these females; all of them when null.
     *
     * @param int[]|null $femaleIds
     * @return int[]
     */
    public function findOpenOrderIdsHoldingFemales(?array $femaleIds, int $companyId): array;

    /**
     * Every service order run on a batch, newest first.
     *
     * @return ServiceOrderEntity[]
     */
    public function findByBatch(int $batchId, int $companyId): array;

    public function isFemaleInOrder(int $orderId, int $femaleId): bool;

    /**
     * Start (or reopen) the exposure of a female to the service.
     */
    public function openFemaleExposure(int $orderId, int $companyId, int $femaleId, string $date, bool $joinedAfterStart): void;

    /**
     * End the open exposure of a female. Returns false when she had none.
     */
    public function closeFemaleExposure(int $orderId, int $femaleId, string $date, ServiceExposureExitReason $reason): bool;

    /**
     * End every open exposure of the order. Returns how many were closed.
     */
    public function closeOpenExposures(int $orderId, string $date, ServiceExposureExitReason $reason): int;

    /**
     * List all service orders of a company.
     *
     * @return \App\Core\Entities\ServiceOrderEntity[]
     */
    public function listAll(int $companyId): array;

    /**
     * Keep on the order the sanitary status a bull was admitted with (null = never evaluated).
     */
    public function recordBullSanitaryStatusAtEntry(int $orderId, int $maleId, ?ReproductiveAptitudeStatus $status): void;

    /**
     * The diagnosis round of the order on that day; created with the first verdict of the day.
     */
    public function findOrCreateDiagnosisRound(int $orderId, int $companyId, string $roundDate): int;

    /**
     * Current verdict row of a female in the order, or null when she is not part of it.
     *
     * @return array{reproductive_status: string, diagnosed_at: ?string, exposed_until: ?string, gestation_id: ?int}|null
     */
    public function findFemaleVerdict(int $orderId, int $femaleId): ?array;

    /**
     * Last verdict the history holds for the female (even if she was reopened as pending since).
     */
    public function findLatestDiagnosedVerdict(int $orderId, int $femaleId): ?FemaleReproductiveVerdict;

    /**
     * Keeps a verdict in the history of its round and makes it the current one of the female.
     */
    public function recordFemaleVerdict(
        int $orderId,
        int $companyId,
        int $femaleId,
        int $roundId,
        FemaleReproductiveVerdict $verdict,
        string $diagnosedAt,
        ?int $userId,
        ?int $gestationId = null,
        ?string $gestationStage = null,
        ?float $gestationMonths = null,
        ?NotDiagnosedReason $notDiagnosedReason = null,
        ?string $notDiagnosedNotes = null
    ): void;

    /**
     * Empty verdicts given while the female was still exposed go back to UNCHECKED: she may have
     * conceived afterwards. The verdict stays in the history. Returns the females reopened.
     *
     * @return int[]
     */
    public function reopenProvisionalEmpties(int $orderId): array;

    /**
     * A female that died or was sold before any verdict will never be diagnosed: in every order
     * still waiting for verdicts she is resolved as EXITED.
     */
    public function markExitedWhereUnchecked(int $femaleId): void;

    /**
     * A pregnancy lost before its order was closed: the female needs a new verdict.
     */
    public function reopenLostPregnancy(int $gestationId): void;

    /**
     * Females of the order, other than the pregnant and the exited ones, still in the batch.
     *
     * @param string[] $verdicts restricts to these verdicts when given
     * @return int[]
     */
    public function findNonPregnantFemalesInBatch(int $orderId, int $batchId, array $verdicts = []): array;

    /**
     * The bulls end their part in the order without leaving the batch (they stay, or leave later
     * through a transfer order).
     */
    public function completeActiveBulls(int $orderId, string $date): void;

    /**
     * Rounds of the order and every female with her current verdict, previous verdict, current
     * batch and gestation data (with its confirmed sire, if any). Read model for the diagnosis screens.
     *
     * @return array{rounds: list<array<string, mixed>>, females: list<array<string, mixed>>}
     */
    public function getDiagnosisDetail(int $orderId, int $companyId): array;
}
