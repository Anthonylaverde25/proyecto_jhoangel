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
        Schema::table('service_order_males', function (Blueprint $table) {
            // Date the bull joined the females; a replacement joins after the order start.
            $table->date('joined_at')->nullable()->after('male_caravan_id');
            // ReproductiveAptitudeStatus when the bull was admitted; null = never evaluated.
            $table->string('sanitary_status_at_entry', 30)->nullable();
        });

        // Backfill from recorded dates only: the replacement date for a replacement bull, the
        // actual start for the bulls the order started with. Orders not started stay null.
        DB::table('service_order_males')->update([
            'joined_at' => DB::raw(
                '(SELECT DATE(sobr.replacement_date) FROM service_order_bull_replacements sobr '
                . 'WHERE sobr.service_order_id = service_order_males.service_order_id '
                . 'AND sobr.replacement_male_caravan_id = service_order_males.male_caravan_id '
                . 'ORDER BY sobr.replacement_date LIMIT 1)'
            ),
        ]);

        DB::table('service_order_males')->whereNull('joined_at')->update([
            'joined_at' => DB::raw(
                '(SELECT so.actual_start_date FROM service_orders so '
                . 'WHERE so.id = service_order_males.service_order_id)'
            ),
        ]);
    }

    public function down(): void
    {
        Schema::table('service_order_males', function (Blueprint $table) {
            $table->dropColumn(['joined_at', 'sanitary_status_at_entry']);
        });
    }
};
