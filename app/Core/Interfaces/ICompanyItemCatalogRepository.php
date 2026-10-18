<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Catalogs\BaseItemCatalog;

interface ICompanyItemCatalogRepository
{
    /** Inserts only what is missing by (company_id, code); never touches what the company edited. */
    public function seedBaseCatalog(int $companyId, BaseItemCatalog $catalog): void;
}
