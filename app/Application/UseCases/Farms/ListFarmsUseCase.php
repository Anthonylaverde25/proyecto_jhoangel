<?php

declare(strict_types=1);

namespace App\Application\UseCases\Farms;

use App\Core\Interfaces\IFarmRepository;
use App\Core\Entities\FarmEntity;

final class ListFarmsUseCase
{
    public function __construct(
        private readonly IFarmRepository $repository
    ) {
    }

    /**
     * @return FarmEntity[]
     */
    public function __invoke(?int $providerId = null): array
    {
        if ($providerId !== null) {
            return $this->repository->findByProviderId($providerId);
        }
        return $this->repository->findAll();
    }
}
