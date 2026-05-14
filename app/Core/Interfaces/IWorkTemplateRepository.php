<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\WorkTemplateEntity;

interface IWorkTemplateRepository extends IRepository
{
    /**
     * @return WorkTemplateEntity[]
     */
    public function findByCompanyId(int $companyId): array;

    public function findById(int $id): ?WorkTemplateEntity;
}
