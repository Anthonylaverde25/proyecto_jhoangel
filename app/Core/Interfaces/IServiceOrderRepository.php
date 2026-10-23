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
     * The active (DRAFT or APPROVED) service running on a batch, as a lightweight reference.
     *
     * @return array{id: int, status: string, is_controlled_service: bool, service_method: string}|null
     */
    public function findActiveServiceOnBatch(int $batchId): ?array;

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
     * batch and gestation data. Read model for the diagnosis screens.
     *
     * @return array{rounds: list<array<string, mixed>>, females: list<array<string, mixed>>}
     */
    public function getDiagnosisDetail(int $orderId, int $companyId): array;
}
