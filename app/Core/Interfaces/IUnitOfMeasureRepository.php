<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\UnitOfMeasureEntity;
use App\Core\Enums\UnitDimension;

interface IUnitOfMeasureRepository
{
    /** @return list<UnitOfMeasureEntity> ordered by display_order */
    public function findAll(int $companyId, bool $includeInactive): array;

    public function findById(int $id, int $companyId): ?UnitOfMeasureEntity;

    public function findBase(UnitDimension $dimension, int $companyId): ?UnitOfMeasureEntity;

    /** Items whose unit this is; $activeOnly narrows to active items (U4, U6). */
    public function countItemsUsing(int $unitId, int $companyId, bool $activeOnly): int;

    public function save(UnitOfMeasureEntity $unit): UnitOfMeasureEntity;
}
