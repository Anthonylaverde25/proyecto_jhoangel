<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Application\DTOs\ItemCatalog\UnitOfMeasureDTO;
use App\Core\Entities\UnitOfMeasureEntity;
use App\Core\Exceptions\ItemCatalogException;
use App\Core\Interfaces\IUnitOfMeasureRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Name, symbol and order always change; dimension and factor only while no item uses the unit (U4).
 */
final class UpdateUnitOfMeasureUseCase
{
    public function __construct(
        private readonly IUnitOfMeasureRepository $repository
    ) {
    }

    public function __invoke(int $id, UnitOfMeasureDTO $dto, int $companyId): UnitOfMeasureEntity
    {
        $unit = $this->repository->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Unit of measure {$id} not found.");

        $unit->rename(
            $dto->name ?? $unit->getName(),
            $dto->symbol ?? $unit->getSymbol(),
            $dto->displayOrder ?? $unit->getDisplayOrder(),
        );

        if ($dto->dimension !== null || $dto->factorSent) {
            $dimension = $dto->dimension ?? $unit->getDimension();
            $factor = $dto->factorSent ? $dto->factorToBase : $unit->getFactorToBase();

            if ($factor !== null && !$unit->isBase() && $this->repository->findBase($dimension, $companyId) === null) {
                throw ItemCatalogException::missingBase($dimension->label());
            }

            $unit->changeConversion($dimension, $factor, $this->repository->countItemsUsing($id, $companyId, false));
        }

        return $this->repository->save($unit);
    }
}
