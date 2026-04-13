<?php

declare(strict_types=1);

namespace App\Application\UseCases;

use App\Core\Interfaces\ICaravanRepository;
use App\Core\Entities\CaravanEntity;

final class ListCaravansUseCase
{
    public function __construct(
        private readonly ICaravanRepository $repository
    ) {
    }

    /**
     * @return CaravanEntity[]
     */
    public function __invoke(): array
    {
        return $this->repository->findAll();
    }
}
