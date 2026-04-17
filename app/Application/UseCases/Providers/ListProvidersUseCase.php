<?php

declare(strict_types=1);

namespace App\Application\UseCases\Providers;

use App\Core\Interfaces\IProviderRepository;
use App\Core\Entities\ProviderEntity;

final class ListProvidersUseCase
{
    public function __construct(
        private readonly IProviderRepository $repository
    ) {
    }

    /**
     * @return ProviderEntity[]
     */
    public function __invoke(): array
    {
        return $this->repository->findAll();
    }
}
