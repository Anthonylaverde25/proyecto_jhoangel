<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\ProviderEntity;

interface IProviderRepository
{
    /**
     * @return ProviderEntity[]
     */
    public function findAll(): array;

    public function findById(int $id): ?ProviderEntity;

    public function findByCuit(string $cuit): ?ProviderEntity;

    public function save(ProviderEntity $provider): ProviderEntity;

    public function delete(int $id): bool;
}
