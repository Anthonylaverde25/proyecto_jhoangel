<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F5 / ADR-5: `2026_09_05_000004` dropped the unique (company_id, caravan_id), so
 * `findByCaravanId` orders by a NULLABLE `last_evaluation_date`. With several evaluations
 * per bull the sanitary lock can read the wrong row. The column becomes mandatory after
 * a faithful backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Backfill before tightening: fall back to the row creation date, never to "today",
        // so the historical ordering stays faithful.
        DB::table('bull_health_evaluations')
            ->whereNull('last_evaluation_date')
            ->update([
                'last_evaluation_date' => DB::raw("DATE(COALESCE(created_at, CURRENT_TIMESTAMP))"),
            ]);

        Schema::table('bull_health_evaluations', function (Blueprint $table) {
            $table->date('last_evaluation_date')->nullable(false)->change();
        });

        // Deterministic tie-breaker for same-day evaluations: the repository orders by
        // (last_evaluation_date DESC, id DESC).
        Schema::table('bull_health_evaluations', function (Blueprint $table) {
            $table->index(['company_id', 'caravan_id', 'last_evaluation_date', 'id'], 'bhe_latest_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bull_health_evaluations', function (Blueprint $table) {
            $table->dropIndex('bhe_latest_lookup_idx');
            $table->date('last_evaluation_date')->nullable()->change();
        });
    }
};
