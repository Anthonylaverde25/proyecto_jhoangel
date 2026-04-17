<?php

declare(strict_types=1);

namespace App\Application\UseCases\Providers;

use App\Application\DTOs\CreateProviderDTO;
use App\Core\Entities\FarmEntity;
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
        $farms = array_map(function (array $farmData) {
            return new FarmEntity(
                id: null,
                name: (string) $farmData['name'],
                renspa: (string) $farmData['renspa'],
                location: isset($farmData['location']) ? (string) $farmData['location'] : null,
                providerId: 0 // Will be set in the repository after provider creation
            );
        }, $dto->farms);

        $entity = new ProviderEntity(
            id: null,
            name: $dto->name,
            commercialName: $dto->commercialName,
            cuit: $dto->cuit,
            location: $dto->location,
            email: $dto->email,
            phone: $dto->phone,
            isActive: true,
            createdAt: null,
            farms: $farms
        );

        return $this->repository->save($entity);
    }
}
