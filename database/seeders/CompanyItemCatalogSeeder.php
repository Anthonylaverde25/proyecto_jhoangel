<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\UseCases\Companies\BootstrapCompanyItemCatalogUseCase;
use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * Base units and categories for every company. Seeding runs without model events, so the company
 * observer that does this on creation never fires here.
 */
class CompanyItemCatalogSeeder extends Seeder
{
    public function run(BootstrapCompanyItemCatalogUseCase $bootstrap): void
    {
        foreach (Company::query()->pluck('id') as $companyId) {
            $bootstrap((int) $companyId);
        }
    }
}
