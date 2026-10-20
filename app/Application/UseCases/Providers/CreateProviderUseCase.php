<?php

declare(strict_types=1);

namespace App\Application\UseCases\Providers;

use App\Application\DTOs\CreateProviderDTO;
use App\Core\Entities\FarmEntity;
use App\Core\Entities\ProviderEntity;
use App\Core\Interfaces\IProviderRepository;
use App\Core\ValueObjects\Cuit;

final class CreateProviderUseCase
{
    public function __construct(
        private readonly IProviderRepository $repository
    ) {
    }

    public function __invoke(CreateProviderDTO $dto): ProviderEntity
    {
        $farms = array_map(function (array $farmData) use ($dto) {
            $renspa = trim((string) ($farmData['renspa'] ?? ''));

            return new FarmEntity(
                id: null,
                name: (string) $farmData['name'],
                renspa: $renspa !== '' ? $renspa : null,
                location: isset($farmData['location']) ? (string) $farmData['location'] : null,
                providerId: 0, // Set by the repository once the provider exists
                companyId: $dto->companyId
            );
        }, $dto->farms);

        $entity = new ProviderEntity(
            id: null,
            name: $dto->name,
            commercialName: $dto->commercialName,
            cuit: Cuit::requireValid($dto->cuit)->value(),
            location: $dto->location,
            email: $dto->email,
            phone: $dto->phone,
            isActive: true,
            createdAt: null,
            farms: $farms,
            companyId: $dto->companyId,
            suppliesLivestock: $dto->suppliesLivestock,
            suppliesItems: $dto->suppliesItems
        );

        return $this->repository->save($entity);
    }
}
