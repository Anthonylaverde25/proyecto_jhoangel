<?php

declare(strict_types=1);

namespace App\Application\UseCases\Farms;

final class FarmUseCases
{
    public function __construct(
        public readonly ListFarmsUseCase $list,
        public readonly CreateFarmUseCase $create,
    ) {
    }
}
