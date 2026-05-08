<?php

declare(strict_types=1);

namespace App\Application\UseCases\Providers;

use App\Core\Entities\ProviderEntity;
use App\Core\Interfaces\IProviderRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class FindProviderUseCase
{
    public function __construct(
        private readonly IProviderRepository $repository
    ) {
    }

    public function __invoke(int $id): ProviderEntity
    {
        $provider = $this->repository->findById($id);

        if (!$provider) {
            throw new ModelNotFoundException("Provider with ID {$id} not found.");
        }

        return $provider;
    }
}
