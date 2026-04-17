<?php

declare(strict_types=1);

namespace App\Application\UseCases\Providers;

final class ProviderUseCases
{
    public function __construct(
        public readonly ListProvidersUseCase $list,
        public readonly CreateProviderUseCase $create,
    ) {
    }
}
