<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\TemplateTypeEntity;

interface ITemplateTypeRepository extends IRepository
{
    /**
     * @return TemplateTypeEntity[]
     */
    public function findByCompanyId(int $companyId): array;

    public function findById(int $id): ?TemplateTypeEntity;
}
