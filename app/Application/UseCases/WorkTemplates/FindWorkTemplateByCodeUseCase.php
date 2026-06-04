<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

use App\Core\Entities\WorkTemplateEntity;
use App\Core\Interfaces\IWorkTemplateRepository;

final class FindWorkTemplateByCodeUseCase
{
    public function __construct(
        private readonly IWorkTemplateRepository $repository
    ) {
    }

    public function __invoke(int $companyId, string $code): ?WorkTemplateEntity
    {
        $templates = $this->repository->findBy([
            'company_id' => $companyId,
            'code' => $code,
        ]);

        if (empty($templates)) {
            return null;
        }

        return $templates[0];
    }
}
