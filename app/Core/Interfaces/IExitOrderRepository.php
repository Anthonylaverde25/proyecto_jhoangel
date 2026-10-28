<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\ExitOrderDteEntity;
use App\Core\Entities\ExitOrderEntity;
use App\Core\ValueObjects\ExitOrderCriteria;

interface IExitOrderRepository
{
    /**
     * Writes the order and its roll, and a history entry when the status changes or metadata comes.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function save(ExitOrderEntity $order, ?int $actionUserId, ?string $reason = null, ?array $metadata = null): ExitOrderEntity;

    public function findById(int $id, int $companyId): ?ExitOrderEntity;

    public function findByCode(string $code, int $companyId): ?ExitOrderEntity;

    /** @return list<ExitOrderEntity> newest first */
    public function list(ExitOrderCriteria $criteria): array;

    /** The last code of the day, read with a lock (inside the transaction that inserts). */
    public function lastCodeWithPrefix(string $prefix, int $companyId): ?string;

    /**
     * Animals pending in an open exit order (E3).
     *
     * @param int[] $caravanIds
     * @return array<int, string> caravan id => code of the order that holds it
     */
    public function findCommittedCaravans(array $caravanIds, int $companyId): array;

    /** Exit orders that name this partner as buyer (C2 for CUSTOMER_LIVESTOCK). */
    public function countByBuyer(int $partnerId, int $companyId): int;

    public function dteNumberTaken(string $dteNumber, int $companyId): bool;

    /** Adds a DTE and a history entry naming it. */
    public function addDte(ExitOrderEntity $order, ExitOrderDteEntity $dte, ?int $actionUserId): void;

    public function removeDte(ExitOrderEntity $order, int $dteId, ?int $actionUserId): bool;
}
