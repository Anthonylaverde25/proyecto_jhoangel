<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\CompanyEntity;
use App\Models\Company;

class CompanyMapper
{
    public static function toEntity(Company $model): CompanyEntity
    {
        return new CompanyEntity(
            id: $model->id,
            name: $model->name,
            renspa: $model->renspa,
            location: $model->location,
            isActive: (bool) $model->is_active,
            role: $model->pivot?->role ?? 'operator'
        );
    }
}
