<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\Caravan;
use App\Models\EntryOrderAnimal;
use App\Models\EntryOrderDte;
use App\Models\EntryOrderIncident;
use App\Models\EntryOrderReceiptSheet;
use Illuminate\Support\Facades\DB;

/**
 * Until a DTE became a count of head, it listed its caravans, created in transit. Those that
 * arrived stay; those in transit are dropped (their head stay in transit on the DTE); those that
 * will not arrive become missing head of the DTE; an unlisted caravan is an arrival excess.
 */
class EntryOrderHeadCountDteMigrationTest extends EntryOrderTestCase
{
    public function test_caravans_listed_the_old_way_become_counts_on_their_dte(): void
    {
        $migration = require database_path('migrations/tenant/2026_10_06_000001_make_entry_dte_a_head_count_document.php');

        $order = $this->createOrder(['head_count' => 3, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $loaded = $this->loadDte($order['id'], 3);
        $received = $this->receiveOn($loaded, $this->animals('MG', 1));
        $dteId = $loaded['dtes'][0]['id'];
        $sheetId = $this->apiAs('POST', "/entry-orders/{$order['id']}/receipt-sheets", ['dte_id' => $dteId])->json('order.receipt_sheets.0.id');
        $migration->down();

        // As the old code left it: one caravan received, one in transit, one that will not arrive.
        foreach (['PENDING' => 'EO-MG-P', 'MISSING' => 'EO-MG-M'] as $status => $tag) {
            $caravan = Caravan::create(['company_id' => $this->company->id, 'identification' => $tag, 'sex' => 'M', 'category_id' => $this->categoryId('TERNERO')]);
            DB::table('entry_order_animals')->insert([
                'company_id' => $this->company->id,
                'entry_order_id' => $order['id'],
                'entry_order_dte_id' => $dteId,
                'caravan_id' => $caravan->id,
                'reception_status' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('entry_order_receipt_sheets')->where('id', $sheetId)->update(['caravan_ids' => json_encode([1, 2])]);
        DB::table('entry_order_incidents')->insert([
            'company_id' => $this->company->id,
            'entry_order_id' => $order['id'],
            'type' => 'UNLISTED_CARAVAN',
            'detail' => 'Caravana sin DTE',
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertSame(1, EntryOrderAnimal::where('entry_order_id', $order['id'])->count());
        $this->assertSame(0, Caravan::whereIn('identification', ['EO-MG-P', 'EO-MG-M'])->count());
        $this->assertSame(1, EntryOrderDte::findOrFail($dteId)->missing_head_count);

        $sheet = EntryOrderReceiptSheet::findOrFail($sheetId);
        $this->assertSame(3, $sheet->dte_head_count);
        $this->assertSame(2, $sheet->expected_head_count);

        $this->assertSame(1, EntryOrderIncident::where('type', 'ARRIVAL_EXCESS')->count());

        $after = $this->apiAs('GET', "/entry-orders/{$order['id']}")->json();
        $this->assertSame(1, $after['received_count']);
        $this->assertSame(1, $after['in_transit_count']);
        $this->assertSame(1, $after['missing_count']);
        $this->assertSame($received['received_count'], $after['received_count']);
    }
}
