<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemPresentation;
use App\Models\ItemProvider;
use App\Models\Provider;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catalog to look at the B1 screens on the first company: a three-level category tree, a company
 * unit (quintal), articles counted in ml, doses and kg, a direct-expense article, a service and a
 * deactivated item. The second company keeps only its base catalog, to show each one sees its own.
 *
 * Creates only what is missing, and every key carries the company (seeders run without events).
 */
class ItemCatalogTestSeeder extends Seeder
{
    private const ITEMS_PROVIDER_CUIT = '30708451238';
    private const MIXED_PROVIDER_CUIT = '30987654321';

    private int $companyId;

    public function run(): void
    {
        $company = Company::query()->orderBy('id')->first();
        if (!$company) {
            $this->command?->warn('ItemCatalogTestSeeder: no hay compañía cargada. Se omite.');

            return;
        }
        $this->companyId = (int) $company->id;

        DB::transaction(function (): void {
            $elTala = Provider::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $this->companyId, 'cuit' => self::ITEMS_PROVIDER_CUIT],
                [
                    'name' => 'Agroveterinaria El Tala',
                    'commercial_name' => 'El Tala',
                    'location' => 'Av. San Martín 1450, Tandil',
                    'is_active' => true,
                    'supplies_livestock' => false,
                    'supplies_items' => true,
                ]
            );

            // An existing livestock seller that also sells salt: the provider with both roles.
            $mixed = Provider::withoutGlobalScopes()
                ->where('company_id', $this->companyId)
                ->where('cuit', self::MIXED_PROVIDER_CUIT)
                ->first();
            $mixed?->update(['supplies_items' => true]);

            $health = $this->category('HEALTH');
            $antiparasitics = $this->category('ANTIPARASITICS', 'Antiparasitarios', $health, 1);
            $endectocides = $this->category('ENDECTOCIDES', 'Endectocidas', $antiparasitics, 1);
            $vaccines = $this->category('VACCINES', 'Vacunas', $health, 2);
            $minerals = $this->category('MINERALS', 'Sales minerales', $this->category('NUTRITION'), 1);
            $maintenance = $this->category('MAINTENANCE');
            $vetServices = $this->category('VET_SERVICES');

            UnitOfMeasure::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $this->companyId, 'code' => 'QQ'],
                ['name' => 'Quintal', 'symbol' => 'qq', 'dimension' => 'MASS', 'factor_to_base' => '100',
                    'is_base' => false, 'is_active' => true, 'display_order' => 4]
            );

            $ivermectin = $this->item('ART-0001', 'Ivermectina 1%', 'ARTICLE', true, 'ML', $endectocides,
                'Endectocida inyectable. Dosis según peso: 1 ml cada 50 kg.');
            $this->presentation($ivermectin, 'Frasco × 50 ml', '50');
            $this->presentation($ivermectin, 'Frasco × 500 ml', '500');
            $this->link($ivermectin, $elTala, 2, true);

            $fmd = $this->item('ART-0002', 'Vacuna aftosa', 'ARTICLE', true, 'DOSE', $vaccines, null);
            $this->presentation($fmd, 'Frasco × 25 dosis', '25');
            $this->presentation($fmd, 'Caja × 250 dosis', '250');
            $this->link($fmd, $elTala, 3, true);

            $salt = $this->item('ART-0003', 'Sal mineral', 'ARTICLE', true, 'KG', $minerals, null);
            $this->presentation($salt, 'Bolsa × 25 kg', '25');
            $this->link($salt, $elTala, 5, true);
            if ($mixed) {
                $this->link($salt, $mixed, 10, false);
            }

            $this->item('ART-0004', 'Alambre liso 17/15', 'ARTICLE', false, 'UNIT', $maintenance, null);

            $visit = $this->item('SRV-0001', 'Visita veterinaria', 'SERVICE', false, 'SERVICE', $vetServices, null);
            $this->link($visit, $elTala, null, true);

            $this->item('ART-0005', 'Garrapaticida X (discontinuado)', 'ARTICLE', true, 'ML', $antiparasitics, null, false);
        });
    }

    /** A base category by code, or a subcategory created under $parent when missing. */
    private function category(string $code, ?string $name = null, ?ItemCategory $parent = null, int $order = 1): ItemCategory
    {
        $existing = ItemCategory::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', $code)->first();
        if ($existing || $name === null) {
            return $existing ?? throw new \RuntimeException("Base category {$code} missing: run CompanyItemCatalogSeeder first.");
        }

        return ItemCategory::withoutGlobalScopes()->create([
            'company_id' => $this->companyId, 'parent_id' => $parent?->id, 'code' => $code,
            'name' => $name, 'is_active' => true, 'display_order' => $order,
        ]);
    }

    private function item(
        string $code, string $name, string $type, bool $trackStock, string $unitCode,
        ItemCategory $category, ?string $description, bool $active = true
    ): Item {
        $unitId = UnitOfMeasure::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', $unitCode)->value('id');

        return Item::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'code' => $code],
            ['name' => $name, 'type' => $type, 'track_stock' => $trackStock, 'unit_of_measure_id' => $unitId,
                'item_category_id' => $category->id, 'description' => $description, 'is_active' => $active]
        );
    }

    private function presentation(Item $item, string $name, string $factor): void
    {
        ItemPresentation::query()->firstOrCreate(
            ['item_id' => $item->id, 'name' => $name],
            ['factor_to_item_unit' => $factor, 'is_active' => true]
        );
    }

    private function link(Item $item, Provider $provider, ?int $leadTimeDays, bool $preferred): void
    {
        ItemProvider::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'item_id' => $item->id, 'provider_id' => $provider->id],
            ['lead_time_days' => $leadTimeDays, 'is_preferred' => $preferred]
        );
    }
}
