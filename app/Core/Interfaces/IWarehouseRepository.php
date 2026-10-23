<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\WarehouseEntity;
use App\Core\Enums\WarehouseKind;
use App\Core\ValueObjects\WarehouseSummary;

interface IWarehouseRepository
{
    /** The default of the kind: the general one always exists (ensureDefault), the tanks' one may not. */
    public function findDefault(int $companyId, WarehouseKind $kind = WarehouseKind::GENERAL): ?WarehouseEntity;

    public function findById(int $id, int $companyId): ?WarehouseEntity;

    /** General ones first, then tanks; the default first within its kind, then by code. @return list<WarehouseEntity> */
    public function findAll(int $companyId): array;

    /** Inserts ALM-01 when the company has no general default warehouse (I11). Idempotent. */
    public function ensureDefault(int $companyId): WarehouseEntity;

    /** A new active warehouse (DD1); it is the default only when its kind has none yet (SW4). */
    public function create(int $companyId, string $code, string $name, WarehouseKind $kind): WarehouseEntity;

    /** Code, name, kind and active flag. The default flag is only set through swapDefault; save may only clear it. */
    public function save(WarehouseEntity $warehouse): WarehouseEntity;

    /** The current default of the same kind stops being it and the given one becomes it, in one transaction (DD2). */
    public function swapDefault(int $companyId, int $newDefaultId): void;

    /** Whether stock ever moved in or out of it (SW1). */
    public function hasMovements(int $id, int $companyId): bool;

    /** @return list<string> */
    public function codes(int $companyId): array;

    public function codeTaken(int $companyId, string $code, ?int $exceptId = null): bool;

    public function nameTaken(int $companyId, string $name, ?int $exceptId = null): bool;

    /** What each warehouse holds, by warehouse id (DD4). @return array<int, WarehouseSummary> */
    public function summaries(int $companyId): array;
}
