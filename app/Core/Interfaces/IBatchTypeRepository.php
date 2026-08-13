<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\BatchTypeEntity;

interface IBatchTypeRepository
{
    /**
     * Get all active batch types for a specific company.
     *
     * @param int $companyId
     * @return array<BatchTypeEntity>
     */
    public function findAllActiveByCompany(int $companyId): array;

    /**
     * Find a batch type by its ID.
     *
     * @param int $id
     * @return BatchTypeEntity|null
     */
    public function findById(int $id): ?BatchTypeEntity;

    /**
     * Find a batch type by code and company.
     *
     * @param string $code
     * @param int $companyId
     * @return BatchTypeEntity|null
     */
    public function findByCodeAndCompany(string $code, int $companyId): ?BatchTypeEntity;

    public function findByCode(string $code): ?BatchTypeEntity;
}

