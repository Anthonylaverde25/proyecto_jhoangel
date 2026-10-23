<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Warehouse kinds (semen delivery S-0): GENERAL or CRYO_TANK (a semen tank). Every existing
 * warehouse stays general. The default becomes one per (company, kind), so uq_wh_one_default is
 * rebuilt in both engines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('kind', 16)->default('GENERAL')->after('name');   // GENERAL | CRYO_TANK
        });

        $this->dropOneDefaultGuard();
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX uq_wh_one_default ON warehouses (company_id, kind) WHERE is_default = 1');

            return;
        }

        DB::statement('CREATE UNIQUE INDEX uq_wh_one_default ON warehouses ((IF(is_default, company_id, NULL)), kind)');
        DB::statement("ALTER TABLE warehouses ADD CONSTRAINT chk_wh_kind CHECK (kind IN ('GENERAL','CRYO_TANK'))");
    }

    public function down(): void
    {
        $this->dropOneDefaultGuard();
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE warehouses DROP CHECK chk_wh_kind');
        }

        // Only the general defaults survive the old one-per-company guard.
        DB::table('warehouses')->where('kind', '!=', 'GENERAL')->update(['is_default' => false]);
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX uq_wh_one_default ON warehouses (company_id) WHERE is_default = 1');

            return;
        }

        DB::statement('CREATE UNIQUE INDEX uq_wh_one_default ON warehouses ((IF(is_default, company_id, NULL)))');
    }

    private function dropOneDefaultGuard(): void
    {
        DB::statement(DB::getDriverName() === 'sqlite'
            ? 'DROP INDEX uq_wh_one_default'
            : 'DROP INDEX uq_wh_one_default ON warehouses');
    }
};
