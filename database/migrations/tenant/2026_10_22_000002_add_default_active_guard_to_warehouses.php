<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The default warehouse is always active (DD3): every provider document enters there. SQLite relies on
 * the domain rule only, like every other CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE warehouses ADD CONSTRAINT chk_wh_default_active CHECK (is_default = 0 OR is_active = 1)');
        DB::statement("ALTER TABLE warehouses ADD CONSTRAINT chk_wh_code CHECK (CHAR_LENGTH(TRIM(code)) > 0)");
        DB::statement("ALTER TABLE warehouses ADD CONSTRAINT chk_wh_name CHECK (CHAR_LENGTH(TRIM(name)) > 0)");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (['chk_wh_default_active', 'chk_wh_code', 'chk_wh_name'] as $name) {
            DB::statement("ALTER TABLE warehouses DROP CHECK {$name}");
        }
    }
};
