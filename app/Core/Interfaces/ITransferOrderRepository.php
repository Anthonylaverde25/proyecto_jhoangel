<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\TransferOrderEntity;

interface ITransferOrderRepository
{
    /**
     * Persists the order, its destinations and its roll. A history line is written when the
     * order is new, when its status changes, or when `$metadata` is given — a second partial
     * round leaves the status where it was and still has to be told.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function save(TransferOrderEntity $order, ?int $actionUserId, ?string $reason = null, ?array $metadata = null): TransferOrderEntity;

    public function findById(int $id, int $companyId): ?TransferOrderEntity;

    public function findByCode(string $code, int $companyId): ?TransferOrderEntity;

    /**
     * @return TransferOrderEntity[] newest first, without history
     */
    public function list(int $companyId, ?string $status = null, ?int $sourceBatchId = null, ?string $kind = null): array;

    /**
     * The highest code already issued with this prefix (e.g. "TR-20260922-"), or null.
     */
    public function lastCodeWithPrefix(string $prefix, int $companyId): ?string;

    /**
     * Caravans of the list still PENDING in an open order, with that order's code.
     *
     * @param int[] $caravanIds
     * @return array<int, string> caravan id => order code
     */
    public function findCommittedCaravans(array $caravanIds, int $companyId): array;

    /**
     * Where each caravan of the list currently is, for the ones that belong to the company.
     *
     * @param int[] $caravanIds
     * @return array<int, int|null> caravan id => batch id
     */
    public function currentBatchOfCaravans(array $caravanIds, int $companyId): array;

    /**
     * The sex of each caravan of the list ('M' or 'H'), for the ones that belong to the company.
     * What a target category is checked against.
     *
     * @param int[] $caravanIds
     * @return array<int, string> caravan id => sex
     */
    public function sexOfCaravans(array $caravanIds, int $companyId): array;
}
