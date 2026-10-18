<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

final class UnitOfMeasureUseCases
{
    public function __construct(
        public readonly ListUnitsOfMeasureUseCase $list,
        public readonly CreateUnitOfMeasureUseCase $create,
        public readonly UpdateUnitOfMeasureUseCase $update,
        public readonly SetUnitOfMeasureActiveUseCase $setActive,
    ) {
    }
}
