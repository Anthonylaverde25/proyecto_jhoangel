<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\WarehouseEntity;
use App\Core\ValueObjects\WarehouseSummary;

interface IWarehouseRepository
{
    public function findDefault(int $companyId): ?WarehouseEntity;

    public function findById(int $id, int $companyId): ?WarehouseEntity;

    /** Default first, then by code. @return list<WarehouseEntity> */
    public function findAll(int $companyId): array;

    /** Inserts ALM-01 when the company has no default warehouse (I11). Idempotent. */
    public function ensureDefault(int $companyId): WarehouseEntity;

    /** A new active, non-default warehouse (DD1). */
    public function create(int $companyId, string $code, string $name): WarehouseEntity;

    /** Code, name and active flag. The default flag only changes through swapDefault. */
    public function save(WarehouseEntity $warehouse): WarehouseEntity;

    /** The current default stops being it and the given one becomes it, in one transaction (DD2). */
    public function swapDefault(int $companyId, int $newDefaultId): void;

    /** @return list<string> */
    public function codes(int $companyId): array;

    public function codeTaken(int $companyId, string $code, ?int $exceptId = null): bool;

    public function nameTaken(int $companyId, string $name, ?int $exceptId = null): bool;

    /** What each warehouse holds, by warehouse id (DD4). @return array<int, WarehouseSummary> */
    public function summaries(int $companyId): array;
}
