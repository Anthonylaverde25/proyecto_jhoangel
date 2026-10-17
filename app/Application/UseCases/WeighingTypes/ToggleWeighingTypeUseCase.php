<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeighingTypes;

use App\Core\Entities\WeighingTypeEntity;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IWeighingTypeRepository;

/**
 * Hides a weighing purpose the farm never uses (a feedlot has no births) from the forms. The
 * weights already recorded under it keep their type.
 */
final class ToggleWeighingTypeUseCase
{
    public function __construct(
        private readonly IWeighingTypeRepository $repository,
    ) {
    }

    public function __invoke(int $id, bool $active): WeighingTypeEntity
    {
        $type = $this->repository->findById($id);

        if ($type === null) {
            throw new DomainException('Tipo de pesaje no encontrado.');
        }

        $type->setActive($active);
        $this->repository->save($type);

        return $type;
    }
}
