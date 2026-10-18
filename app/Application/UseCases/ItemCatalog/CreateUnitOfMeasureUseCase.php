<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Application\DTOs\ItemCatalog\UnitOfMeasureDTO;
use App\Core\Entities\UnitOfMeasureEntity;
use App\Core\Exceptions\ItemCatalogException;
use App\Core\Interfaces\IUnitOfMeasureRepository;

/**
 * A new unit is never a base (DB1): it either equals N of its dimension's base or does not convert.
 */
final class CreateUnitOfMeasureUseCase
{
    public function __construct(
        private readonly IUnitOfMeasureRepository $repository
    ) {
    }

    public function __invoke(UnitOfMeasureDTO $dto, int $companyId): UnitOfMeasureEntity
    {
        // U7: a convertible unit is expressed in its base, so the base must exist.
        if ($dto->factorToBase !== null && $this->repository->findBase($dto->dimension, $companyId) === null) {
            throw ItemCatalogException::missingBase($dto->dimension->label());
        }

        return $this->repository->save(new UnitOfMeasureEntity(
            null,
            $companyId,
            strtoupper((string) $dto->code),
            (string) $dto->name,
            (string) $dto->symbol,
            $dto->dimension,
            $dto->factorToBase,
            false,
            true,
            $dto->displayOrder ?? 100,
        ));
    }
}
