<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a service order was born: issued in the system, or registered after the service ended in the
 * field. Declared, never inferred from dates. Every existing order was issued. The PLANNED status
 * needs no change: `status` is a string, and the one-active-per-batch guard lists its statuses, so
 * a planned order does not take the batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->string('kind', 20)->default('ISSUED')->after('status')
                ->comment('ISSUED or REGISTERED (born ended, after the fact)');
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
