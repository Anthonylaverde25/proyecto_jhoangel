<?php

declare(strict_types=1);

namespace App\Application\UseCases\Breeds;

use App\Core\Entities\BreedEntity;
use App\Core\Interfaces\IBreedRepository;

final class ListBreedsUseCase
{
    public function __construct(
        private readonly IBreedRepository $repository
    ) {
    }

    /**
     * @return array<BreedEntity>
     */
    public function __invoke(): array
    {
        return $this->repository->getAll();
    }
}
