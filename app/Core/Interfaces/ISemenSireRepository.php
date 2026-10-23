<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\SemenSireEntity;

interface ISemenSireRepository
{
    /** By name, with the number of active semen items of each. @return list<SemenSireEntity> */
    public function findAll(int $companyId, ?string $search, bool $includeInactive): array;

    public function findById(int $id, int $companyId): ?SemenSireEntity;

    public function findByRegistry(string $registryCode, int $companyId): ?SemenSireEntity;

    public function findByCaravan(int $caravanId, int $companyId): ?SemenSireEntity;

    public function save(SemenSireEntity $sire): SemenSireEntity;

    public function hasActiveItems(int $sireId, int $companyId): bool;

    /** A male caravan of the company, in any state (DA6): its identification and breed, or null. @return array{identification: string, breed_id: ?int}|null */
    public function ownBull(int $caravanId, int $companyId): ?array;
}
