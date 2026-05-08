<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Core\Entities\UserEntity;

readonly class UserAuthResult
{
    /**
     * @param \App\Core\Entities\CompanyEntity[] $companies
     */
    public function __construct(
        public UserEntity $user,
        public string $accessToken,
        public array $companies
    ) {}
}
