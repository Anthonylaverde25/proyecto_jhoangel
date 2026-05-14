<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

use App\Core\Interfaces\ITemplateTypeRepository;
use App\Core\Entities\TemplateTypeEntity;

final class ListTemplateTypesUseCase
{
    public function __construct(
        private readonly ITemplateTypeRepository $repository
    ) {
    }

    /**
     * @return TemplateTypeEntity[]
     */
    public function __invoke(int $companyId): array
    {
        return $this->repository->findByCompanyId($companyId);
    }
}
