<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the order was planned before the movement (PLANNED) or registered after the
     * movement already happened in the field (REGISTERED). Declared by the user, never inferred
     * from dates.
     */
    public function up(): void
    {
        Schema::table('transfer_orders', function (Blueprint $table) {
            $table->string('kind', 16)->default('PLANNED')->after('status');
            $table->index(['company_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('transfer_orders', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'kind']);
            $table->dropColumn('kind');
        });
    }
};
