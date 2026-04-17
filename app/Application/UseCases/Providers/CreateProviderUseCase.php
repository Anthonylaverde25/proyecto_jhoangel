<?php

declare(strict_types=1);

namespace App\Application\UseCases\Providers;

use App\Application\DTOs\CreateProviderDTO;
use App\Core\Entities\ProviderEntity;
use App\Core\Interfaces\IProviderRepository;

final class CreateProviderUseCase
{
    public function __construct(
        private readonly IProviderRepository $repository
    ) {
    }

    public function __invoke(CreateProviderDTO $dto): ProviderEntity
    {
        $entity = new ProviderEntity(
            id: null,
            name: $dto->name,
            commercialName: $dto->commercialName,
            cuit: $dto->cuit,
            location: $dto->location,
            email: $dto->email,
            phone: $dto->phone,
            isActive: true
        );

        return $this->repository->save($entity);
    }
}
