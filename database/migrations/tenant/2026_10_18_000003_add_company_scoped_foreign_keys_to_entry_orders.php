<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An entry order's provider and origin farm must belong to the order's company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entry_orders', function (Blueprint $table) {
            $table->dropForeign(['provider_id']);
            $table->dropForeign(['farm_id']);
        });

        Schema::table('entry_orders', function (Blueprint $table) {
            $table->foreign(['provider_id', 'company_id'])           // entry_orders_provider_id_company_id_foreign
                ->references(['id', 'company_id'])->on('providers')->restrictOnDelete();
            $table->foreign(['farm_id', 'company_id'])               // entry_orders_farm_id_company_id_foreign
                ->references(['id', 'company_id'])->on('farms')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('entry_orders', function (Blueprint $table) {
            $table->dropForeign(['farm_id', 'company_id']);
            $table->dropForeign(['provider_id', 'company_id']);
        });

        Schema::table('entry_orders', function (Blueprint $table) {
            $table->foreign('provider_id')->references('id')->on('providers')->restrictOnDelete();
            $table->foreign('farm_id')->references('id')->on('farms')->restrictOnDelete();
        });
    }
};
