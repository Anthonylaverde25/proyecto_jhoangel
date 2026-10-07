<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An entry order is finished only once every head received is an animal with its caravan. The
 * orders that closed with head received by count and still without caravan go back to RECEIVED
 * ("Recibida · por identificar"), keeping their closing reason for when they close. The column is
 * a string: no schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pending = DB::table('entry_order_dtes')
            ->select('entry_order_id')
            ->groupBy('entry_order_id')
            ->havingRaw('SUM(uncaravaned_head_count) > 0');

        DB::table('entry_orders')
            ->whereIn('status', ['COMPLETED', 'CLOSED_INCOMPLETE'])
            ->whereIn('id', $pending)
            ->update(['status' => 'RECEIVED', 'closed_at' => null]);
    }

    public function down(): void
    {
        // An order received entirely by count closed as it did before: incomplete with its reason, complete without.
        DB::table('entry_orders')->where('status', 'RECEIVED')->whereNotNull('closing_reason')->update(['status' => 'CLOSED_INCOMPLETE', 'closed_at' => now()]);
        DB::table('entry_orders')->where('status', 'RECEIVED')->update(['status' => 'COMPLETED', 'closed_at' => now()]);
    }
};
