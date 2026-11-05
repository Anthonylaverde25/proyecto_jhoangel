<?php

declare(strict_types=1);

use Database\Seeders\WeighingTypeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Adds "Pesada General Promedio" to the catalog of each tenant; the seeder keeps is_active. */
    public function up(): void
    {
        (new WeighingTypeSeeder())->run();
    }

    /** A type already used by a weighing stays: its orders and weights point at it. */
    public function down(): void
    {
        $id = DB::table('weighing_types')->where('code', 'BATCH_AVERAGE')->value('id');
        if ($id === null) {
            return;
        }

        $used = DB::table('weighing_orders')->where('weighing_type_id', $id)->exists()
            || DB::table('caravan_weights')->where('weighing_type_id', $id)->exists();

        if (!$used) {
            DB::table('batch_weights')->where('weighing_type_id', $id)->update(['weighing_type_id' => null]);
            DB::table('weighing_types')->where('id', $id)->delete();
        }
    }
};
