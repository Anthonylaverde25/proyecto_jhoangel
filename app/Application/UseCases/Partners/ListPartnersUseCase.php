<?php

declare(strict_types=1);

namespace App\Application\UseCases\Partners;

use App\Core\Entities\PartnerEntity;
use App\Core\Enums\PartnerRole;
use App\Core\Interfaces\IPartnerRepository;

final class ListPartnersUseCase
{
    public function __construct(
        private readonly IPartnerRepository $repository
    ) {
    }

    /**
     * @param list<PartnerRole>|null $roles only partners holding any of them; null = all (C3)
     * @return PartnerEntity[]
     */
    public function __invoke(?array $roles = null): array
    {
        return $this->repository->findAll($roles);
    }
}
