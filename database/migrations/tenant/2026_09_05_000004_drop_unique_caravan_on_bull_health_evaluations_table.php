<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bull_health_evaluations', function (Blueprint $table) {
            // Drop the single unique constraint so that a bull can have multiple longitudinal evaluations over time
            $table->dropUnique(['company_id', 'caravan_id']);

            // Add index for fast historical querying
            $table->index(['company_id', 'caravan_id', 'last_evaluation_date'], 'bhe_company_caravan_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bull_health_evaluations', function (Blueprint $table) {
            $table->dropIndex('bhe_company_caravan_date_idx');
            $table->unique(['company_id', 'caravan_id']);
        });
    }
};
