<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

final class WorkTemplateUseCases
{
    public function __construct(
        public readonly ListWorkTemplatesUseCase $listTemplates,
        public readonly FindWorkTemplateByCodeUseCase $findTemplateByCode,
        public readonly IdentifyWorkTemplateUseCase $identifyTemplate,
    ) {
    }
}
