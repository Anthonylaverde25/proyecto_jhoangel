<?php

declare(strict_types=1);

namespace App\Application\UseCases\Breeds;

final class BreedUseCases
{
    public function __construct(
        public readonly ListBreedsUseCase $list,
    ) {
    }
}
