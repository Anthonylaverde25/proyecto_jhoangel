<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\ProviderEntity;

interface IProviderRepository
{
    /**
     * Providers of the active company.
     *
     * @param 'livestock'|'items'|null $supplies only providers with that role; null = all
     * @return ProviderEntity[]
     */
    public function findAll(?string $supplies = null): array;

    public function findById(int $id): ?ProviderEntity;

    public function findByCuit(string $cuit, int $companyId): ?ProviderEntity;

    public function save(ProviderEntity $provider): ProviderEntity;

    /** Persists only the two role flags; the provider's farms are left untouched. */
    public function updateRoles(ProviderEntity $provider): void;

    /** Persists only the identity and contact fields; roles and farms are left untouched. */
    public function updateDetails(ProviderEntity $provider): void;

    public function delete(int $id): bool;
}
