<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Core\Interfaces\ICaravanRepository;
use App\Core\Entities\CaravanEntity;

final class ListCaravansUseCase
{
    public function __construct(
        private readonly ICaravanRepository $repository
    ) {
    }

    /**
     * @param string|null $scope 'own' | 'external' | 'all'
     * @return CaravanEntity[]
     */
    public function __invoke(?string $scope = 'own'): array
    {
        return $this->repository->findAll($scope);
    }
}

