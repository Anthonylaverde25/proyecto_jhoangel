<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\WarehouseEntity;

interface IWarehouseRepository
{
    public function findDefault(int $companyId): ?WarehouseEntity;

    /** @return list<WarehouseEntity> */
    public function findAll(int $companyId): array;

    /** Inserts MAIN when the company has no default warehouse (I11). Idempotent. */
    public function ensureDefault(int $companyId): WarehouseEntity;
}
