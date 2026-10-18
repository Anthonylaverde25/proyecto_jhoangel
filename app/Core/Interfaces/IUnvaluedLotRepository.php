<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\UnvaluedIssueEntity;
use App\Core\Entities\UnvaluedLotEntity;

/**
 * Lots of unvalued delivery notes and the outbound lines that took from them (delivery C). Runs
 * inside the ledger's transaction; lock* reads lock the rows (DS7).
 */
interface IUnvaluedLotRepository
{
    public function create(UnvaluedLotEntity $lot): UnvaluedLotEntity;

    public function save(UnvaluedLotEntity $lot): void;

    /** PENDING lots of an item in a warehouse, oldest first, locked. @return list<UnvaluedLotEntity> */
    public function lockPending(int $warehouseId, int $itemId, int $companyId): array;

    /**
     * Lots of the given delivery-note lines, locked, keyed by delivery-note line id.
     *
     * @param list<int> $deliveryNoteLineIds
     * @return array<int, UnvaluedLotEntity>
     */
    public function lockByDeliveryNoteLines(array $deliveryNoteLineIds, int $companyId): array;

    /**
     * Lots valued by the given invoice lines, locked.
     *
     * @param list<int> $invoiceLineIds
     * @return list<UnvaluedLotEntity>
     */
    public function lockValuedBy(array $invoiceLineIds, int $companyId): array;

    /**
     * Lots by id, locked, keyed by id.
     *
     * @param list<int> $ids
     * @return array<int, UnvaluedLotEntity>
     */
    public function lockByIds(array $ids, int $companyId): array;

    /** Numbers of the operations whose issues from the lot were not given back (Q1). @return list<int> */
    public function openIssueDocumentNumbers(int $lotId, int $companyId): array;

    /**
     * PENDING lots of an item, for its Stock tab: which delivery note, how much waits, how much left.
     *
     * @return list<array{warehouse_id: int, delivery_note_id: int, delivery_note_label: string, issued_at: string, quantity: string, remaining_quantity: string, consumed_quantity: string}>
     */
    public function pendingOfItem(int $itemId, int $companyId): array;

    /** @param list<UnvaluedIssueEntity> $issues */
    public function createIssues(array $issues): void;

    /** Issues of an outbound line not yet given back. @return list<UnvaluedIssueEntity> */
    public function openIssuesOfLine(int $itemDocumentLineId, int $companyId): array;

    public function markIssueReversed(int $issueId, int $reversalLineId, int $companyId): void;

    /** Every issue of the lot takes its cost; null clears it (its invoice was voided). */
    public function setIssueCosts(int $lotId, ?string $unitCost, int $companyId): void;
}
