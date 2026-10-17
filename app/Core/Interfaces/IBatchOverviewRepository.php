<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

/**
 * Raw facts about the animals currently in a batch, read for the batch page. Every method
 * counts the caravans the batch holds today: a document that took an animal elsewhere no
 * longer speaks for this batch.
 */
interface IBatchOverviewRepository
{
    /**
     * @return array{M: int, H: int}
     */
    public function sexComposition(int $batchId): array;

    /**
     * @return list<array{category_id: ?int, name: ?string, count: int}> largest first
     */
    public function categoryComposition(int $batchId): array;

    /**
     * Females in the batch, and how many of them carry a current gestation.
     *
     * @return array{females: int, pregnant: int}
     */
    public function pregnancyCounts(int $batchId): array;

    /**
     * Females in the batch past their due date without calving (OVERDUE on a birth order that
     * was not cancelled). Closing the order does not close the alert.
     *
     * @return list<array{birth_order_id: int, code: string, count: int}>
     */
    public function overdueBirths(int $batchId, int $companyId): array;

    /**
     * Open incidents of the entry orders that send animals to the batch.
     *
     * @return list<array{entry_order_id: int, code: string, count: int}>
     */
    public function openEntryIncidents(int $batchId, int $companyId): array;

    /**
     * Issued or partial orders with animals still pending that sit in the batch today.
     *
     * @return list<array{kind: string, id: int, code: string, pending_count: int}>
     */
    public function pendingOrders(int $batchId, int $companyId): array;

    /**
     * The service running on the batch (DRAFT or APPROVED), if any.
     *
     * @return array{id: int, code: string, status: string, planned_start_date: ?string}|null
     */
    public function activeServiceOrder(int $batchId): ?array;
}
