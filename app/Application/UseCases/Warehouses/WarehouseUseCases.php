<?php

declare(strict_types=1);

namespace App\Application\UseCases\Warehouses;

final class WarehouseUseCases
{
    public function __construct(
        public readonly ListWarehousesUseCase $list,
        public readonly SuggestWarehouseCodeUseCase $nextCode,
        public readonly CreateWarehouseUseCase $create,
        public readonly UpdateWarehouseUseCase $update,
        public readonly SetDefaultWarehouseUseCase $setDefault,
        public readonly SetWarehouseActiveUseCase $setActive,
    ) {
    }
}
