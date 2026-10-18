<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every existing company its base units and categories (D14, DB6). The data is frozen
 * here on purpose: later edits to BaseItemCatalog must not change what this migration did.
 * Idempotent: inserts only what is missing by (company_id, code).
 */
return new class extends Migration
{
    /** @var list<array{0:string,1:string,2:string,3:string,4:?string,5:bool,6:int}> */
    private const UNITS = [
        ['KG', 'Kilogramo', 'kg', 'MASS', '1', true, 1],
        ['G', 'Gramo', 'g', 'MASS', '0.001', false, 2],
        ['TN', 'Tonelada', 'tn', 'MASS', '1000', false, 3],
        ['L', 'Litro', 'l', 'VOLUME', '1', true, 4],
        ['ML', 'Mililitro', 'ml', 'VOLUME', '0.001', false, 5],
        ['UNIT', 'Unidad', 'u', 'COUNT', '1', true, 6],
        ['DOSE', 'Dosis', 'dosis', 'COUNT', null, false, 7],
        ['HEAD', 'Cabeza', 'cab', 'COUNT', null, false, 8],
        ['SERVICE', 'Servicio', 'serv', 'COUNT', null, false, 9],
        ['HOUR', 'Hora', 'h', 'TIME', '1', true, 10],
        ['WORKDAY', 'Jornada', 'jor', 'TIME', null, false, 11],
        ['HA', 'Hectárea', 'ha', 'AREA', '1', true, 12],
        ['M', 'Metro', 'm', 'LENGTH', '1', true, 13],
    ];

    /** @var list<array{0:string,1:string,2:int}> */
    private const CATEGORIES = [
        ['HEALTH', 'Sanidad', 1],
        ['NUTRITION', 'Nutrición', 2],
        ['REPRODUCTION', 'Reproducción', 3],
        ['IDENTIFICATION', 'Identificación', 4],
        ['FUEL', 'Combustibles', 5],
        ['MAINTENANCE', 'Mantenimiento', 6],
        ['FREIGHT', 'Fletes', 7],
        ['VET_SERVICES', 'Servicios veterinarios', 8],
        ['FIELD_SERVICES', 'Servicios de campo', 9],
    ];

    public function up(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $units = DB::table('units_of_measure')->where('company_id', $companyId)->pluck('code')->all();
            foreach (self::UNITS as [$code, $name, $symbol, $dimension, $factor, $isBase, $order]) {
                if (in_array($code, $units, true)) {
                    continue;
                }
                DB::table('units_of_measure')->insert([
                    'company_id' => $companyId, 'code' => $code, 'name' => $name, 'symbol' => $symbol,
                    'dimension' => $dimension, 'factor_to_base' => $factor, 'is_base' => $isBase,
                    'is_active' => true, 'display_order' => $order, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $categories = DB::table('item_categories')->where('company_id', $companyId)->pluck('code')->all();
            foreach (self::CATEGORIES as [$code, $name, $order]) {
                if (in_array($code, $categories, true)) {
                    continue;
                }
                DB::table('item_categories')->insert([
                    'company_id' => $companyId, 'parent_id' => null, 'code' => $code, 'name' => $name,
                    'is_active' => true, 'display_order' => $order, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Removes the base rows that no item uses. Rows in use stay: deleting them would orphan
     * items, and M05–M07 drop everything anyway when rolled back further.
     */
    public function down(): void
    {
        DB::table('units_of_measure')
            ->whereIn('code', array_column(self::UNITS, 0))
            ->whereNotExists(fn ($q) => $q->from('items')->whereColumn('items.unit_of_measure_id', 'units_of_measure.id'))
            ->delete();

        DB::table('item_categories')
            ->whereIn('code', array_column(self::CATEGORIES, 0))
            ->whereNull('parent_id')
            ->whereNotExists(fn ($q) => $q->from('items')->whereColumn('items.item_category_id', 'item_categories.id'))
            ->whereNotExists(fn ($q) => $q->from('item_categories as c')->whereColumn('c.parent_id', 'item_categories.id'))
            ->delete();
    }
};
