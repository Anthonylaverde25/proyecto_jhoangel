<?php

declare(strict_types=1);

namespace App\Application\UseCases\Farms;

use App\Application\DTOs\CreateFarmDTO;
use App\Core\Entities\FarmEntity;
use App\Core\Interfaces\IFarmRepository;

final class CreateFarmUseCase
{
    public function __construct(
        private readonly IFarmRepository $repository
    ) {
    }

    public function __invoke(CreateFarmDTO $dto): FarmEntity
    {
        $entity = new FarmEntity(
            id: null,
            name: $dto->name,
            renspa: $dto->renspa,
            location: $dto->location,
            providerId: $dto->providerId,
            isActive: true
        );

        return $this->repository->save($entity);
    }
}
