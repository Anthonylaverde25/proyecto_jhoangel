<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Core\Entities\UnitOfMeasureEntity;
use App\Core\Interfaces\IUnitOfMeasureRepository;

final class ListUnitsOfMeasureUseCase
{
    public function __construct(
        private readonly IUnitOfMeasureRepository $repository
    ) {
    }

    /** @return list<UnitOfMeasureEntity> */
    public function __invoke(int $companyId, bool $includeInactive): array
    {
        return $this->repository->findAll($companyId, $includeInactive);
    }
}
