<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\WeaningOrderEntity;

interface IWeaningOrderRepository
{
    /**
     * Persists the order, its destinations and its roll. A history line is written when the order
     * is new, when its status changes, or when `$metadata` is given.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function save(WeaningOrderEntity $order, ?int $actionUserId, ?string $reason = null, ?array $metadata = null): WeaningOrderEntity;

    public function findById(int $id, int $companyId): ?WeaningOrderEntity;

    public function findByCode(string $code, int $companyId): ?WeaningOrderEntity;

    /**
     * @return WeaningOrderEntity[] newest first, without history
     */
    public function list(int $companyId, ?string $status = null, ?string $kind = null): array;

    /**
     * The highest code already issued with this prefix (e.g. "DS-20260928-"), or null.
     */
    public function lastCodeWithPrefix(string $prefix, int $companyId): ?string;

    /**
     * Calves of the list still PENDING in an open weaning order, with that order's code.
     *
     * @param int[] $caravanIds
     * @return array<int, string> caravan id => order code
     */
    public function findCommittedCaravans(array $caravanIds, int $companyId): array;

    /**
     * Each calf of the list that belongs to the company: where it is, its sex, whether it is still
     * nursing and when it was born. What an order is checked against before it commits a calf.
     *
     * @param int[] $caravanIds
     * @return array<int, array{identification: string, batch_id: ?int, sex: string, is_nursing: ?bool, birth_date: ?string}> caravan id => facts
     */
    public function calfFacts(array $caravanIds, int $companyId): array;
}
