<?php

declare(strict_types=1);

namespace App\Application\UseCases\Companies;

use App\Core\Catalogs\BaseItemCatalog;
use App\Core\Interfaces\ICompanyItemCatalogRepository;

/**
 * Gives a company its base units and categories (D14, DB6). Idempotent: runs from the company
 * observer, the seeder and any future company creation without duplicating or overwriting.
 */
final class BootstrapCompanyItemCatalogUseCase
{
    public function __construct(
        private readonly ICompanyItemCatalogRepository $repository,
        private readonly BaseItemCatalog $catalog,
    ) {
    }

    public function __invoke(int $companyId): void
    {
        $this->repository->seedBaseCatalog($companyId, $this->catalog);
    }
}
