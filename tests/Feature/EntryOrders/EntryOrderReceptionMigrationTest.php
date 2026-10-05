<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\EntryOrder;
use App\Models\EntryOrderAnimal;
use Illuminate\Support\Facades\DB;

/**
 * Until the reception existed, a DTE was loaded when its animals arrived: those caravans are
 * received on the DTE's entry day, and PARTIAL becomes AWAITING_DTE.
 */
class EntryOrderReceptionMigrationTest extends EntryOrderTestCase
{
    public function test_data_loaded_the_old_way_is_received_and_partial_becomes_awaiting_dte(): void
    {
        $migration = require database_path('migrations/tenant/2026_10_03_000001_add_reception_and_incidents_to_entry_orders.php');

        $order = $this->createOrder(['head_count' => 3, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $this->loadDte($order['id'], $this->animals('MG', 2, 'M', 1));

        $migration->down();

        // As the old code left it: a DTE with its entry day, the order PARTIAL.
        DB::table('entry_order_dtes')->where('entry_order_id', $order['id'])->update(['entered_at' => '2026-09-30']);
        DB::table('entry_orders')->where('id', $order['id'])->update(['status' => 'PARTIAL']);
        DB::table('entry_order_histories')->insert([
            'company_id' => $this->company->id,
            'entry_order_id' => $order['id'],
            'from_status' => 'AWAITING_DTE',
            'to_status' => 'PARTIAL',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertSame('AWAITING_DTE', EntryOrder::findOrFail($order['id'])->status);
        $this->assertSame(0, DB::table('entry_order_histories')->where('to_status', 'PARTIAL')->orWhere('from_status', 'PARTIAL')->count());

        $lines = EntryOrderAnimal::where('entry_order_id', $order['id'])->get();
        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertSame('RECEIVED', $line->reception_status);
            $this->assertSame('MANUAL', $line->reception_method);
            $this->assertSame('2026-09-30', $line->received_at->toDateString());
        }
    }
}
