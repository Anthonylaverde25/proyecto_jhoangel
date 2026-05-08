<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\CompanyEntity;

interface ICompanyRepository
{
    /**
     * @return CompanyEntity[]
     */
    public function getForUser(int $userId): array;

    public function findById(int $id): ?CompanyEntity;
}
