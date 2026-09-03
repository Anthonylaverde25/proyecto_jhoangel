<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\PathogenEntity;

interface IPathogenRepository
{
    /**
     * @return array<PathogenEntity>
     */
    public function findAll(): array;

    public function findById(int $id): ?PathogenEntity;

    public function findByCode(string $code): ?PathogenEntity;
}
