<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The status reached by withdrawing the torada is about the bulls, not about the diagnosis: the
 * diagnosis can be loaded from the first day of service. AWAITING_DIAGNOSIS becomes BULLS_WITHDRAWN
 * on the orders and on their status history.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->renameStatus('AWAITING_DIAGNOSIS', 'BULLS_WITHDRAWN');
    }

    public function down(): void
    {
        $this->renameStatus('BULLS_WITHDRAWN', 'AWAITING_DIAGNOSIS');
    }

    private function renameStatus(string $from, string $to): void
    {
        DB::table('service_orders')->where('status', $from)->update(['status' => $to]);
        DB::table('service_order_histories')->where('from_status', $from)->update(['from_status' => $to]);
        DB::table('service_order_histories')->where('to_status', $from)->update(['to_status' => $to]);
    }
};
