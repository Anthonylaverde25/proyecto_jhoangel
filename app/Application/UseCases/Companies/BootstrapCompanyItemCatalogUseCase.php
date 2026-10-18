<?php

declare(strict_types=1);

namespace App\Application\UseCases\Companies;

use App\Core\Catalogs\BaseItemCatalog;
use App\Core\Interfaces\ICompanyItemCatalogRepository;
use App\Core\Interfaces\IWarehouseRepository;

/**
 * Gives a company its base units and categories (D14, DB6) and its default warehouse (D8, I11).
 * Idempotent: runs from the company observer, the seeder and any future company creation without
 * duplicating or overwriting.
 */
final class BootstrapCompanyItemCatalogUseCase
{
    public function __construct(
        private readonly ICompanyItemCatalogRepository $repository,
        private readonly BaseItemCatalog $catalog,
        private readonly IWarehouseRepository $warehouses,
    ) {
    }

    public function __invoke(int $companyId): void
    {
        $this->repository->seedBaseCatalog($companyId, $this->catalog);
        $this->warehouses->ensureDefault($companyId);
    }
}
