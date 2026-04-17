<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\FarmEntity;

interface IFarmRepository
{
    /**
     * @return FarmEntity[]
     */
    public function findAll(): array;

    public function findById(int $id): ?FarmEntity;

    /**
     * @return FarmEntity[]
     */
    public function findByProviderId(int $providerId): array;

    public function save(FarmEntity $farm): FarmEntity;

    public function delete(int $id): bool;
}
