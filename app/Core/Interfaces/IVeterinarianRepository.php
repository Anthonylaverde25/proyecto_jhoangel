<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\VeterinarianEntity;

interface IVeterinarianRepository
{
    /**
     * @return array<VeterinarianEntity>
     */
    public function findAll(int $companyId, bool $activeOnly = true): array;

    public function findById(int $id, int $companyId): ?VeterinarianEntity;

    public function findByLicenseNumber(string $licenseNumber, int $companyId): ?VeterinarianEntity;

    public function findByUserId(int $userId, int $companyId): ?VeterinarianEntity;

    public function save(VeterinarianEntity $veterinarian): VeterinarianEntity;

    /**
     * Batch ids the professional is currently assigned to (unassigned_at IS NULL).
     *
     * @return list<int>
     */
    public function findActiveBatchIds(int $veterinarianId, int $companyId): array;
}
