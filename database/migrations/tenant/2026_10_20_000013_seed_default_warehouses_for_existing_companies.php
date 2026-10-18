<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every existing company its default warehouse (D8, I11). Frozen data. Idempotent: skips a
 * company that already has a default warehouse.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $withDefault = DB::table('warehouses')->where('is_default', true)->pluck('company_id')->all();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            if (in_array($companyId, $withDefault, false)) {
                continue;
            }
            DB::table('warehouses')->insert([
                'company_id' => $companyId, 'code' => 'MAIN', 'name' => 'Almacén principal',
                'is_default' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Rows created here are removed with the table by M17's down().
    }
};
