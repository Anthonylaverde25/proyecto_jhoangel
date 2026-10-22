<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives every company the SEMEN, LIVESTOCK_TREATMENT and PADDOCK_TREATMENT system roots. The data is
 * frozen here on purpose. A row with a legacy code, or a root the company already created with the
 * same name, is promoted instead of duplicated. The display order ties with the neighbour on purpose
 * (Sanidad/Tratamiento de…, Reproducción/Semen): the name breaks the tie.
 */
return new class extends Migration
{
    /** @var list<array{code:string,name:string,legacy:list<string>,aliases:list<string>,order:int}> */
    private const CATEGORIES = [
        ['code' => 'LIVESTOCK_TREATMENT', 'name' => 'Tratamiento de ganado', 'legacy' => ['TREATMENT'], 'aliases' => ['tratamiento de ganado', 'tratamientos de ganado'], 'order' => 1],
        ['code' => 'PADDOCK_TREATMENT', 'name' => 'Tratamiento de potrero', 'legacy' => [], 'aliases' => ['tratamiento de potrero', 'tratamientos de potrero'], 'order' => 1],
        ['code' => 'SEMEN', 'name' => 'Semen', 'legacy' => [], 'aliases' => ['semen'], 'order' => 3],
    ];

    public function up(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (self::CATEGORIES as $category) {
                $this->ensure((int) $companyId, $category, $now);
            }
        }
    }

    /** Rows stay: items may already hang from them. Only the system mark is dropped. */
    public function down(): void
    {
        DB::table('item_categories')
            ->whereIn('code', array_column(self::CATEGORIES, 'code'))
            ->update(['is_system' => false]);
    }

    /** @param array{code:string,name:string,legacy:list<string>,aliases:list<string>,order:int} $category */
    private function ensure(int $companyId, array $category, mixed $now): void
    {
        $placeholders = implode(',', array_fill(0, count($category['aliases']), '?'));

        $existing = DB::table('item_categories')
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q
                ->whereIn('code', [$category['code'], ...$category['legacy']])
                ->orWhere(fn ($q) => $q->whereNull('parent_id')->whereRaw("LOWER(TRIM(name)) IN ({$placeholders})", $category['aliases'])))
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$category['code']])
            ->first();

        if ($existing !== null) {
            DB::table('item_categories')->where('id', $existing->id)->update([
                'parent_id' => null, 'code' => $category['code'], 'name' => $category['name'],
                'is_active' => true, 'is_system' => true, 'updated_at' => $now,
            ]);

            return;
        }

        DB::table('item_categories')->insert([
            'company_id' => $companyId, 'parent_id' => null, 'code' => $category['code'], 'name' => $category['name'],
            'is_active' => true, 'display_order' => $category['order'], 'is_system' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
};
