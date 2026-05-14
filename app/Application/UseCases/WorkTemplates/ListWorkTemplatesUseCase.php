<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

use App\Core\Interfaces\IWorkTemplateRepository;
use App\Core\Entities\WorkTemplateEntity;

final class ListWorkTemplatesUseCase
{
    public function __construct(
        private readonly IWorkTemplateRepository $repository
    ) {
    }

    /**
     * @return WorkTemplateEntity[]
     */
    public function __invoke(int $companyId): array
    {
        return $this->repository->findByCompanyId($companyId);
    }
}
