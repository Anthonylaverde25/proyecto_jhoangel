<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batches become a target of company-scoped foreign keys, and an external batch's
 * provider farm must belong to the batch's company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropForeign(['farm_id']);
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->unique(['id', 'company_id'], 'uq_batches_id_company');
            $table->foreign(['farm_id', 'company_id'])               // batches_farm_id_company_id_foreign
                ->references(['id', 'company_id'])->on('farms')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropForeign(['farm_id', 'company_id']);
            $table->dropUnique('uq_batches_id_company');
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->foreign('farm_id')->references('id')->on('farms')->restrictOnDelete();
        });
    }
};
