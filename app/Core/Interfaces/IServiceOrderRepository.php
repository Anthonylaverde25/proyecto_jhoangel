<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\ServiceOrderEntity;

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
     * Active orders are in status DRAFT or APPROVED.
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
     * Move animals physically to a destination batch and log their movements.
     *
     * @param int[] $caravanIds
     */
    public function moveAnimalsToBatch(array $caravanIds, int $batchId, int $companyId, int $actionUserId): void;

    /**
     * List all service orders of a company.
     *
     * @return \App\Core\Entities\ServiceOrderEntity[]
     */
    public function listAll(int $companyId): array;
}
