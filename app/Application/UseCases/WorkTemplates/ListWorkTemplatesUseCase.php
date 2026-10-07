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

    /** Official documents read by the AI (a SENASA TRI) are not sheets to print or scan. */
    public const EXTERNAL_DOCUMENT = 'EXTERNAL_DOCUMENT';

    /**
     * @return WorkTemplateEntity[]
     */
    public function __invoke(int $companyId): array
    {
        return array_values(array_filter(
            $this->repository->findByCompanyId($companyId),
            fn (WorkTemplateEntity $template) => $template->getCategory() !== self::EXTERNAL_DOCUMENT
        ));
    }
}
