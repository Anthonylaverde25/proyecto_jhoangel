<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\CompanyEntity;
use App\Core\Interfaces\ICompanyRepository;
use App\Models\User;
use App\Application\Mappers\CompanyMapper;

class EloquentCompanyRepository implements ICompanyRepository
{
    /**
     * @return CompanyEntity[]
     */
    public function getForUser(int $userId): array
    {
        $user = User::with('companies')->find($userId);

        if (!$user) {
            return [];
        }

        return $user->companies->map(function ($company) {
            return CompanyMapper::toEntity($company);
        })->toArray();
    }
}
