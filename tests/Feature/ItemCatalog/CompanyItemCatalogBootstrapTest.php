<?php

declare(strict_types=1);

namespace Tests\Feature\ItemCatalog;

use App\Application\UseCases\Companies\BootstrapCompanyItemCatalogUseCase;
use App\Models\Company;
use App\Models\ItemCategory;
use App\Models\UnitOfMeasure;

/**
 * Every company starts with 13 units and 13 root system categories of its own (D14, DB6).
 */
class CompanyItemCatalogBootstrapTest extends ItemCatalogTestCase
{
    public function test_a_new_company_receives_its_base_catalog(): void
    {
        $company = Company::create(['name' => 'Campo Nuevo Test']);

        $this->assertSame(13, UnitOfMeasure::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertSame(13, ItemCategory::withoutGlobalScopes()->where('company_id', $company->id)->whereNull('parent_id')->where('is_system', true)->count());
        $this->assertSame('Alimentos', ItemCategory::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'FEED')->value('name'));
        $this->assertSame(['LIVESTOCK_TREATMENT', 'PADDOCK_TREATMENT', 'SEMEN'], ItemCategory::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('code', ['LIVESTOCK_TREATMENT', 'PADDOCK_TREATMENT', 'SEMEN'])->where('is_system', true)->orderBy('code')->pluck('code')->all());
        $this->assertSame(6, UnitOfMeasure::withoutGlobalScopes()->where('company_id', $company->id)->where('is_base', true)->count());
    }

    public function test_running_it_again_neither_duplicates_nor_overwrites(): void
    {
        $company = Company::create(['name' => 'Campo Nuevo Test']);
        UnitOfMeasure::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'KG')
            ->update(['name' => 'Kilo']);

        app(BootstrapCompanyItemCatalogUseCase::class)((int) $company->id);

        $this->assertSame(13, UnitOfMeasure::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertSame('Kilo', UnitOfMeasure::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'KG')->value('name'));
    }

    public function test_each_company_lists_only_its_own_units(): void
    {
        $this->apiAs('POST', '/units-of-measure', [
            'code' => 'ARR', 'name' => 'Arroba', 'symbol' => '@', 'dimension' => 'MASS', 'factor_to_base' => 11.5,
        ])->assertStatus(201);

        $own = array_column($this->apiAs('GET', '/units-of-measure')->assertOk()->json(), 'code');
        $other = array_column($this->asOther(fn () => $this->apiAs('GET', '/units-of-measure'))->json(), 'code');

        $this->assertContains('ARR', $own);
        $this->assertNotContains('ARR', $other);
        $this->assertContains('KG', $other);
    }
}
