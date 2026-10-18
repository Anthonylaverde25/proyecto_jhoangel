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
     * @param 'livestock'|'items'|null $supplies only providers with that role; null = all
     * @return ProviderEntity[]
     */
    public function __invoke(?string $supplies = null): array
    {
        return $this->repository->findAll($supplies);
    }
}
