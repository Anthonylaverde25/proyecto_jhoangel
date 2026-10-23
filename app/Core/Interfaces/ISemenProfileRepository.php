<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\SemenProfileEntity;
use App\Core\Enums\SemenType;

interface ISemenProfileRepository
{
    public function findByItem(int $itemId, int $companyId): ?SemenProfileEntity;

    public function findBySireAndType(int $sireId, SemenType $type, int $companyId): ?SemenProfileEntity;

    public function save(SemenProfileEntity $profile): SemenProfileEntity;

    /** @param list<int> $itemIds @return array<int, SemenProfileEntity> by item id */
    public function forItems(array $itemIds, int $companyId): array;
}
