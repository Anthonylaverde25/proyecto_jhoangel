<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\BatchEntity;

interface IBatchRepository
{
    /**
     * @return BatchEntity[]
     */
    public function findAll(): array;

    public function findById(int $id): ?BatchEntity;

    public function findByNameAndFarmId(string $name, int $farmId): ?BatchEntity;

    /**
     * @return BatchEntity[]
     */
    public function findByFarmId(int $farmId): array;

    public function save(BatchEntity $batch): BatchEntity;

    public function delete(int $id): bool;
}
