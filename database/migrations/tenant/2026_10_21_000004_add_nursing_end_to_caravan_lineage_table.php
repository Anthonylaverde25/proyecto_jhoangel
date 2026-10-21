<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The end of the time at foot is a fact of the calf: when, why and, for a weaning, which
        // kind. It no longer depends on the calf having moved to a weaning batch.
        Schema::table('caravan_lineage', function (Blueprint $table) {
            $table->date('nursing_ended_at')->nullable()->after('is_nursing');
            // WEANED or DIED.
            $table->string('nursing_end_reason', 16)->nullable()->after('nursing_ended_at');
            // TRADITIONAL, ANTICIPATED or EARLY: only for a weaning, chosen by whoever weaned.
            $table->string('weaning_type', 16)->nullable()->after('nursing_end_reason');
        });

        // What is already known: weanings done by an order and deaths registered. A calf weaned
        // before weaning orders existed keeps the date and type unknown, never invented.
        DB::table('weaning_order_animals')
            ->join('weaning_orders', 'weaning_orders.id', '=', 'weaning_order_animals.weaning_order_id')
            ->where('weaning_order_animals.status', 'WEANED')
            ->orderBy('weaning_order_animals.id')
            ->get(['weaning_order_animals.caravan_id', 'weaning_order_animals.weaned_at', 'weaning_orders.weaning_date', 'weaning_orders.weaning_type'])
            ->each(fn ($row) => DB::table('caravan_lineage')
                ->where('caravan_id', $row->caravan_id)
                ->where('is_nursing', false)
                ->update([
                    'nursing_ended_at' => substr((string) ($row->weaning_date ?? $row->weaned_at), 0, 10),
                    'nursing_end_reason' => 'WEANED',
                    'weaning_type' => $row->weaning_type,
                ]));

        DB::table('caravan_deaths')
            ->orderBy('id')
            ->get(['caravan_id', 'death_date'])
            ->each(fn ($row) => DB::table('caravan_lineage')
                ->where('caravan_id', $row->caravan_id)
                ->update([
                    'nursing_ended_at' => substr((string) $row->death_date, 0, 10),
                    'nursing_end_reason' => 'DIED',
                ]));
    }

    public function down(): void
    {
        Schema::table('caravan_lineage', function (Blueprint $table) {
            $table->dropColumn(['nursing_ended_at', 'nursing_end_reason', 'weaning_type']);
        });
    }
};
