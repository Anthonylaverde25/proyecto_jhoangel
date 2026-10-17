<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage and months of a gestation are what the producer declared at the diagnosis, and many
 * only declare "pregnant". A default of head / 3 months invented a calving distribution.
 *
 * Existing rows keep their values: there is no way to tell a declared head / 3.0 from a default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caravan_gestations', function (Blueprint $table) {
            $table->string('gestation_stage')->nullable()->default(null)->change();
            $table->decimal('gestation_months', 3, 1)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        DB::table('caravan_gestations')->whereNull('gestation_stage')->update(['gestation_stage' => 'head']);
        DB::table('caravan_gestations')->whereNull('gestation_months')->update(['gestation_months' => 3.0]);

        Schema::table('caravan_gestations', function (Blueprint $table) {
            $table->string('gestation_stage')->nullable(false)->default('head')->change();
            $table->decimal('gestation_months', 3, 1)->nullable(false)->default(3.0)->change();
        });
    }
};
