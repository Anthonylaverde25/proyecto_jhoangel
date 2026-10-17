<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service order codes (SO-YYYY-NNN) are numbered per company, like every other order, but the
 * column was unique across the whole tenant: the first service of a second company took
 * "SO-2026-001", already used by the first one, and could not be saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropUnique('service_orders_code_unique');
            $table->unique(['company_id', 'code'], 'uq_so_company_code');
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropUnique('uq_so_company_code');
            $table->unique('code');
        });
    }
};
