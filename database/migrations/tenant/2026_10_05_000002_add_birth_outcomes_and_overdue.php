<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAR-01 results and reloads.
 *
 * - `birth_order_animals.calf_sex`: the sex written for a calf born dead (NM) or that died at foot
 *   (M). A live calf keeps reading it from its caravan.
 * - `birth_order_animals.loss_reason_code`: the real reason of a loss registered outside the sheet
 *   (abortion, reabsorption…) that closed the line.
 * - `birth_order_animals.overdue_reported_at` / `overdue_notes`: the day an N ("no parió") was
 *   observed, and what was written with it. The line stays open, as OVERDUE.
 * - `caravan_gestations.calving_overdue_reported_at`: the same alert, on the female. It is open while
 *   the gestation is current, so it stays visible after the order is closed.
 *
 * `birth_order_animals.status` gains BORN_DIED and OVERDUE and `outcome` gains PERINATAL_DEATH; both
 * columns are strings and need no change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('birth_order_animals', function (Blueprint $table) {
            $table->string('calf_sex', 1)->nullable()->after('calf_batch_id');
            $table->string('loss_reason_code', 32)->nullable()->after('outcome');
            $table->date('overdue_reported_at')->nullable()->after('event_date');
            $table->text('overdue_notes')->nullable()->after('overdue_reported_at');
        });

        Schema::table('caravan_gestations', function (Blueprint $table) {
            $table->date('calving_overdue_reported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('caravan_gestations', function (Blueprint $table) {
            $table->dropColumn('calving_overdue_reported_at');
        });

        Schema::table('birth_order_animals', function (Blueprint $table) {
            $table->dropColumn(['calf_sex', 'loss_reason_code', 'overdue_reported_at', 'overdue_notes']);
        });
    }
};
