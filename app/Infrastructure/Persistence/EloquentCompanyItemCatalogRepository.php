<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Catalogs\BaseItemCatalog;
use App\Core\Interfaces\ICompanyItemCatalogRepository;
use Illuminate\Support\Facades\DB;

/**
 * Reads what the company already has and inserts the rest. No insertOrIgnore: it would also
 * silence a CHECK violation.
 */
class EloquentCompanyItemCatalogRepository implements ICompanyItemCatalogRepository
{
    public function seedBaseCatalog(int $companyId, BaseItemCatalog $catalog): void
    {
        DB::transaction(function () use ($companyId, $catalog) {
            $now = now();

            $units = DB::table('units_of_measure')->where('company_id', $companyId)->pluck('code')->all();
            foreach ($catalog->units() as $unit) {
                if (in_array($unit['code'], $units, true)) {
                    continue;
                }
                DB::table('units_of_measure')->insert([
                    'company_id' => $companyId,
                    'code' => $unit['code'],
                    'name' => $unit['name'],
                    'symbol' => $unit['symbol'],
                    'dimension' => $unit['dimension']->value,
                    'factor_to_base' => $unit['factor'],
                    'is_base' => $unit['is_base'],
                    'is_active' => true,
                    'display_order' => $unit['order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $categories = DB::table('item_categories')->where('company_id', $companyId)->pluck('code')->all();
            foreach ($catalog->categories() as $category) {
                if (in_array($category['code'], $categories, true)) {
                    continue;
                }
                DB::table('item_categories')->insert([
                    'company_id' => $companyId,
                    'parent_id' => null,
                    'code' => $category['code'],
                    'name' => $category['name'],
                    'is_active' => true,
                    'display_order' => $category['order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }
}
