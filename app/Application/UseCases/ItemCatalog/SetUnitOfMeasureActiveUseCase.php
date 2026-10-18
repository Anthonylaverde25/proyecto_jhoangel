<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Core\Entities\UnitOfMeasureEntity;
use App\Core\Interfaces\IUnitOfMeasureRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class SetUnitOfMeasureActiveUseCase
{
    public function __construct(
        private readonly IUnitOfMeasureRepository $repository
    ) {
    }

    public function __invoke(int $id, bool $active, int $companyId): UnitOfMeasureEntity
    {
        $unit = $this->repository->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Unit of measure {$id} not found.");

        $unit->setActive($active, $active ? 0 : $this->repository->countItemsUsing($id, $companyId, true));

        return $this->repository->save($unit);
    }
}
