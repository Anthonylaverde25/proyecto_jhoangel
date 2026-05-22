<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\ActivityEntity;

interface IActivityRepository
{
    /**
     * @return ActivityEntity[]
     */
    public function findAll(): array;

    /**
     * @param int $companyId
     * @return ActivityEntity[]
     */
    public function findEnabledByCompany(int $companyId): array;

    /**
     * @param int $companyId
     * @param int $activityId
     * @param bool $isEnabled
     * @return bool
     */
    public function toggleActivity(int $companyId, int $activityId, bool $isEnabled): bool;

    /**
     * @param string $code
     * @return ActivityEntity|null
     */
    public function findByCode(string $code): ?ActivityEntity;
}
