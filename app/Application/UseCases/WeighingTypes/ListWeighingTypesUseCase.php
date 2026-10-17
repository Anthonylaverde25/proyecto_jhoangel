<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeighingTypes;

use App\Core\Entities\WeighingTypeEntity;
use App\Core\Interfaces\IWeighingTypeRepository;

final class ListWeighingTypesUseCase
{
    public function __construct(
        private readonly IWeighingTypeRepository $repository,
    ) {
    }

    /** @return WeighingTypeEntity[] */
    public function __invoke(bool $onlyActive = false): array
    {
        return $this->repository->findAll($onlyActive);
    }
}
