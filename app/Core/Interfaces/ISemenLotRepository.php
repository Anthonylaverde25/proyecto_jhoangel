<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\SemenLotEntity;

interface ISemenLotRepository
{
    public function findById(int $id, int $companyId): ?SemenLotEntity;

    public function findByCode(int $itemId, string $code, int $companyId): ?SemenLotEntity;

    public function create(SemenLotEntity $lot): SemenLotEntity;

    /** Locks the lot's balance in the tank, creating it at 0 when missing; returns the quantity. */
    public function lockBalance(int $lotId, int $warehouseId, int $companyId): string;

    public function setBalance(int $lotId, int $warehouseId, int $companyId, string $quantity): void;

    /**
     * Lots of an item with their balance per tank, codes first.
     *
     * @return list<array{lot: SemenLotEntity, balances: array<int, string>}> balances by warehouse id
     */
    public function lotsOfItem(int $itemId, int $companyId): array;

    /** @return array<int, string> sum of lot balances by warehouse id */
    public function totalsByWarehouse(int $itemId, int $companyId): array;
}
