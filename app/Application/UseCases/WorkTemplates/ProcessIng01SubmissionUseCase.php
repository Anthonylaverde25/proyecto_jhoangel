<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

use App\Application\DTOs\Ing01\Ing01SubmissionDTO;
use App\Application\Services\Ing01TemplateProcessor;

final class ProcessIng01SubmissionUseCase
{
    public function __construct(
        private readonly Ing01TemplateProcessor $processor
    ) {
    }

    /**
     * Execute the processing and persistence of an ING-01 submission.
     *
     * @param Ing01SubmissionDTO $dto
     * @return array<string, mixed>
     */
    public function __invoke(Ing01SubmissionDTO $dto): array
    {
        return $this->processor->process($dto);
    }
}
