<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A company *is* its own farm: farms only stores the origin farms of providers.
 * Own batches carry farm_id = NULL (as CreateBatchDialog already creates them).
 */
return new class extends Migration
{
    public function up(): void
    {
        $ownFarmIds = DB::table('farms')->whereNull('provider_id')->pluck('id');

        if (DB::table('entry_orders')->whereIn('farm_id', $ownFarmIds)->exists()) {
            throw new RuntimeException('Entry orders reference an own farm as origin; fix them before migrating.');
        }

        DB::table('batches')->whereIn('farm_id', $ownFarmIds)->update(['farm_id' => null]);
        DB::table('farms')->whereIn('id', $ownFarmIds)->delete();

        Schema::table('farms', function (Blueprint $table) {
            $table->dropUnique('farms_renspa_unique');
            $table->dropForeign(['provider_id']);
        });

        Schema::table('farms', function (Blueprint $table) {
            $table->string('renspa')->nullable()->change();
        });

        DB::table('farms')->where('renspa', 'NO_DEFINIDO')->update(['renspa' => null]);

        Schema::table('farms', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
            $table->unsignedBigInteger('provider_id')->nullable(false)->change();
        });

        Schema::table('farms', function (Blueprint $table) {
            $table->unique(['company_id', 'renspa'], 'uq_farms_company_renspa');
            $table->unique(['id', 'company_id'], 'uq_farms_id_company');
            $table->foreign(['provider_id', 'company_id'])           // farms_provider_id_company_id_foreign
                ->references(['id', 'company_id'])->on('providers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('farms', function (Blueprint $table) {
            $table->dropForeign(['provider_id', 'company_id']);
            $table->dropUnique('uq_farms_id_company');
            $table->dropUnique('uq_farms_company_renspa');
        });

        Schema::table('farms', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_id')->nullable()->change();
            $table->unsignedBigInteger('company_id')->nullable()->change();
        });

        // The old column was NOT NULL and globally unique: give each missing RENSPA a distinct placeholder.
        $placeholder = DB::getDriverName() === 'sqlite' ? "'NO_DEFINIDO-' || id" : "CONCAT('NO_DEFINIDO-', id)";
        DB::table('farms')->whereNull('renspa')->update(['renspa' => DB::raw($placeholder)]);

        Schema::table('farms', function (Blueprint $table) {
            $table->string('renspa')->nullable(false)->change();
        });

        Schema::table('farms', function (Blueprint $table) {
            $table->unique('renspa', 'farms_renspa_unique');
            $table->foreign('provider_id')->references('id')->on('providers')->restrictOnDelete();
        });

        // Not restored: the deleted own-farm rows. Own batches stay with farm_id = NULL,
        // which the previous code already treated as own.
    }
};
