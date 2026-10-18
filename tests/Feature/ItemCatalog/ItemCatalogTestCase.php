<?php

declare(strict_types=1);

namespace Tests\Feature\ItemCatalog;

use App\Models\Company;
use App\Models\ItemCategory;
use App\Models\Provider;
use App\Models\UnitOfMeasure;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * A tenant with two companies, each with its base catalog (the company observer creates it).
 */
abstract class ItemCatalogTestCase extends VeterinaryTestCase
{
    protected Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->other = Company::where('id', '!=', $this->company->id)->first()
            ?? Company::create(['name' => 'Campo Vecino Test']);
        $this->linkUserToCompany($this->user, (int) $this->other->id);
    }

    protected function unitId(string $code, ?Company $company = null): int
    {
        return (int) UnitOfMeasure::withoutGlobalScopes()
            ->where('company_id', ($company ?? $this->company)->id)
            ->where('code', $code)
            ->value('id');
    }

    protected function categoryId(string $code, ?Company $company = null): int
    {
        return (int) ItemCategory::withoutGlobalScopes()
            ->where('company_id', ($company ?? $this->company)->id)
            ->where('code', $code)
            ->value('id');
    }

    /** @param array<string, mixed> $overrides */
    protected function createItem(array $overrides = []): int
    {
        return (int) $this->apiAs('POST', '/items', $overrides + [
            'name' => 'Ivermectina 1%',
            'behavior' => 'STOCKED_ARTICLE',
            'unit_of_measure_id' => $this->unitId('ML'),
        ])->assertStatus(201)->json('id');
    }

    protected function provider(string $cuit, bool $suppliesItems = true, ?Company $company = null): Provider
    {
        return Provider::withoutGlobalScopes()->create([
            'company_id' => ($company ?? $this->company)->id,
            'name' => 'Proveedor ' . $cuit,
            'cuit' => $cuit,
            'supplies_livestock' => !$suppliesItems,
            'supplies_items' => $suppliesItems,
        ]);
    }

    /** Runs a request under the other company and comes back. */
    protected function asOther(callable $request): mixed
    {
        $own = $this->company;
        $this->company = $this->other;
        try {
            return $request();
        } finally {
            $this->company = $own;
        }
    }
}
