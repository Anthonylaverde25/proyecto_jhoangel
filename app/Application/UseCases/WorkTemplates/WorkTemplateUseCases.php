<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

final class WorkTemplateUseCases
{
    public function __construct(
        public readonly ListTemplateTypesUseCase $listTypes,
        public readonly ListWorkTemplatesUseCase $listTemplates,
    ) {
    }
}
