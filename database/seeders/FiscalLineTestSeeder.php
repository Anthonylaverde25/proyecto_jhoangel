<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Seeder;

/**
 * What the line reading test images need (Delivery G, part 06 §3): "Rollo de avena" on the first
 * company, the item the paper calls just "Avena" (the user's example). It sits next to "Rollo de
 * alfalfa", which must never be taken for it. No links are seeded: they are not remembered (G2).
 *
 * Found by name, so an item already created by hand is kept; created only when missing, with the
 * next free ART- code. Image generator: ai-agent/scripts/generate_fiscal_invoice_images.py.
 */
class FiscalLineTestSeeder extends Seeder
{
    public const OAT_BALE = 'Rollo de avena';

    public function run(): void
    {
        $company = Company::query()->orderBy('id')->first();
        if (!$company) {
            $this->command?->warn('FiscalLineTestSeeder: no hay compañía cargada. Se omite.');

            return;
        }
        $companyId = (int) $company->id;

        $exists = Item::withoutGlobalScopes()->where('company_id', $companyId)->where('name', self::OAT_BALE)->exists();
        if ($exists) {
            return;
        }

        $unitId = UnitOfMeasure::withoutGlobalScopes()->where('company_id', $companyId)->where('code', 'UNIT')->value('id');
        $categoryId = ItemCategory::withoutGlobalScopes()->where('company_id', $companyId)->where('code', 'NUTRITION')->value('id');
        if ($unitId === null) {
            $this->command?->warn('FiscalLineTestSeeder: falta la unidad UNIT (correr CompanyItemCatalogSeeder). Se omite.');

            return;
        }

        Item::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'code' => $this->nextArticleCode($companyId),
            'name' => self::OAT_BALE,
            'description' => 'Rollo de avena para suplementar en invierno.',
            'type' => 'ARTICLE',
            'track_stock' => true,
            'unit_of_measure_id' => $unitId,
            'item_category_id' => $categoryId,
            'is_active' => true,
        ]);
    }

    private function nextArticleCode(int $companyId): string
    {
        $max = Item::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', 'like', 'ART-%')
            ->pluck('code')
            ->map(fn (string $code) => (int) substr($code, 4))
            ->max() ?? 0;

        return sprintf('ART-%04d', $max + 1);
    }
}
