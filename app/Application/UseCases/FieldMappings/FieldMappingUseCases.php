<?php

declare(strict_types=1);

namespace App\Application\UseCases\FieldMappings;

final class FieldMappingUseCases
{
    public function __construct(
        public readonly LearnFieldMappingUseCase $learn,
        public readonly ResolveFieldMappingsUseCase $resolve,
    ) {
    }
}
