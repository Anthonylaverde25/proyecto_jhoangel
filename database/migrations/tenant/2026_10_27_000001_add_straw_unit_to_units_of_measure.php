<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The "Pajuela" (straw) base unit for every existing company (semen S-A). Not convertible, like DOSE.
 * Frozen data, like M10. Idempotent: inserts only where the company lacks the STRAW code.
 */
return new class extends Migration
{
    private const CODE = 'STRAW';

    public function up(): void
    {
        $now = now();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $exists = DB::table('units_of_measure')->where('company_id', $companyId)->where('code', self::CODE)->exists();
            if ($exists) {
                continue;
            }
            DB::table('units_of_measure')->insert([
                'company_id' => $companyId, 'code' => self::CODE, 'name' => 'Pajuela', 'symbol' => 'paj',
                'dimension' => 'COUNT', 'factor_to_base' => null, 'is_base' => false,
                'is_active' => true, 'display_order' => 14, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /** Removes the unit where no item uses it. */
    public function down(): void
    {
        DB::table('units_of_measure')
            ->where('code', self::CODE)
            ->whereNotExists(fn ($q) => $q->from('items')->whereColumn('items.unit_of_measure_id', 'units_of_measure.id'))
            ->delete();
    }
};
