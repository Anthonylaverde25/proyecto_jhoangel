<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\ItemDocumentEntity;
use App\Core\ValueObjects\ItemDocumentFilters;

/**
 * Stock operations of a company. Append-only (I3): no update or delete.
 */
interface IItemDocumentRepository
{
    public function findByIdempotencyKey(string $key, int $companyId): ?ItemDocumentEntity;

    /** With lines, their movements and the reversal that cancelled it, if any. */
    public function findById(int $id, int $companyId): ?ItemDocumentEntity;

    /** The operation a provider document generated (not its reversal). */
    public function findByFiscalDocument(int $fiscalDocumentId, int $companyId): ?ItemDocumentEntity;

    /** @return array{data: list<ItemDocumentEntity>, total: int} */
    public function search(ItemDocumentFilters $filters, int $companyId): array;

    public function nextNumber(int $companyId): int;

    /** Inserts header and valuated lines; returns them with their ids, in the same order. */
    public function create(ItemDocumentEntity $document): ItemDocumentEntity;

    /**
     * Batch history (logical schema 4.4): consumption lines plus registered provider-document lines
     * charged to the batch, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function batchHistory(int $batchId, int $companyId): array;
}
