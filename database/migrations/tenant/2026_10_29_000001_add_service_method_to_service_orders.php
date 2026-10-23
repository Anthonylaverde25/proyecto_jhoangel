<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How the females of a service order are served (semen S-B): bulls (NATURAL, every existing order)
 * or straws of semen (AI). Repaso bulls of an AI order do not change its method.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->string('service_method', 10)->default('NATURAL')->after('service_type');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE service_orders ADD CONSTRAINT chk_so_service_method CHECK (service_method IN ('NATURAL','AI'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE service_orders DROP CHECK chk_so_service_method');
        }
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn('service_method');
        });
    }
};
