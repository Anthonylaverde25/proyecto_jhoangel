<?php

declare(strict_types=1);

namespace App\Infrastructure\Observers;

use App\Application\UseCases\Companies\BootstrapCompanyItemCatalogUseCase;
use App\Models\Company;

/**
 * Every company is born with its base catalog. Seeders run without model events, so
 * CompanyItemCatalogSeeder covers them separately.
 */
class CompanyItemCatalogObserver
{
    public function __construct(
        private readonly BootstrapCompanyItemCatalogUseCase $bootstrap
    ) {
    }

    public function created(Company $company): void
    {
        ($this->bootstrap)((int) $company->id);
    }
}
