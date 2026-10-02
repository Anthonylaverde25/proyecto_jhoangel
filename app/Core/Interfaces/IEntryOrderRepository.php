<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\EntryOrderEntity;

interface IEntryOrderRepository
{
    /**
     * Persists the order, its breeds and the DTEs not stored yet with their caravans. A history line
     * is written when the order is new, when its status changes, or when `$metadata` is given.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function save(EntryOrderEntity $order, ?int $actionUserId, ?string $reason = null, ?array $metadata = null): EntryOrderEntity;

    public function findById(int $id, int $companyId): ?EntryOrderEntity;

    public function findByCode(string $code, int $companyId): ?EntryOrderEntity;

    /**
     * @return EntryOrderEntity[] newest first, with their DTEs but without caravans or history
     */
    public function list(int $companyId, ?string $status = null, ?int $providerId = null): array;

    /**
     * The highest code already issued with this prefix (e.g. "EN-20261001-"), or null.
     */
    public function lastCodeWithPrefix(string $prefix, int $companyId): ?string;

    /**
     * The highest order number of the company, read with a lock; 0 when there is none.
     */
    public function lastNumber(int $companyId): int;

    /**
     * The code of the order a DTE number was already loaded into, or null.
     */
    public function orderCodeOfDte(string $dteNumber, int $companyId): ?string;

    /**
     * A short summary of the order each batch was born from.
     *
     * @param int[] $batchIds
     * @return array<int, array{id: int, code: string, status: string, status_label: string, head_count: int, entered_count: int}>
     */
    public function summariesByBatch(array $batchIds, int $companyId): array;
}
