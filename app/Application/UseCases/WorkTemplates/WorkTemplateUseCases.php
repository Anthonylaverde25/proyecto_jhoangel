<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

final class WorkTemplateUseCases
{
    public function __construct(
        public readonly ListWorkTemplatesUseCase $listTemplates,
        public readonly FindWorkTemplateByCodeUseCase $findTemplateByCode,
        public readonly IdentifyWorkTemplateUseCase $identifyTemplate,
        public readonly ProcessIng01SubmissionUseCase $processIng01,
        public readonly ProcessLser01SubmissionUseCase $processLser01,
        public readonly ProcessDest01SubmissionUseCase $processDest01,
        public readonly ProcessCact01SubmissionUseCase $processCact01,
    ) {
    }
}
