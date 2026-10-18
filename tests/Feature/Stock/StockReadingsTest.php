<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Models\Company;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * What the screens read: catalog, warehouses, item list and file, critical level, batch history,
 * the in-use guards on B1 and the reconciliation.
 */
class StockReadingsTest extends StockTestCase
{
    public function test_the_fiscal_catalog_is_seeded_and_read_only(): void
    {
        $types = $this->apiAs('GET', '/fiscal-document-types')->assertOk()->json();
        $this->assertCount(7, $types);
        $this->assertNotContains('DELIVERY_NOTE', array_column($types, 'code'));

        $taxes = $this->apiAs('GET', '/taxes')->assertOk()->json();
        $this->assertCount(28, $taxes);
        $vat = collect($taxes)->firstWhere('code', 'VAT');
        $this->assertCount(8, $vat['rates']);
        $this->assertSame('LINE', $vat['applies_to']);

        $this->apiAs('POST', '/taxes', ['code' => 'NEW'])->assertStatus(405);
    }

    public function test_every_company_has_its_main_warehouse(): void
    {
        $new = Company::create(['name' => 'Campo nuevo ' . Str::random(4)]);

        $this->assertSame(1, Warehouse::withoutGlobalScopes()->where('company_id', $new->id)->where('is_default', true)->count());
        $this->apiAs('GET', '/warehouses')->assertOk()->assertJsonPath('0.code', 'MAIN');
    }

    public function test_the_item_list_carries_the_balance_and_filters_alerts(): void
    {
        [$item] = $this->stockedItem();
        $this->consume([$this->stockLine($item, '5')])->assertStatus(201);

        $row = collect($this->apiAs('GET', '/items?search=Ivermectina test')->assertOk()->json())->firstWhere('id', $item);
        $this->assertSame('-5.0000', $row['stock']['quantity']);
        $this->assertTrue($row['stock']['has_negative']);

        $alerts = $this->apiAs('GET', '/items?stock_alert=1')->assertOk()->json();
        $this->assertContains($item, array_column($alerts, 'id'));
    }

    public function test_the_item_file_shows_movements_last_purchase_and_preloads_vat(): void
    {
        [$item, $bottle] = $this->stockedItem();
        $this->apiAs('PUT', "/items/{$item}/providers", ['providers' => [['provider_id' => $this->seller->id, 'is_preferred' => true]]])->assertOk();
        $line = $this->line($item, '10', '12000', $bottle);
        $line['taxes'] = [['tax_id' => $this->taxId('VAT'), 'tax_rate_id' => $this->rateId('VAT_10_5')]];
        $this->invoice([$line], ['number' => 123])->assertStatus(201);

        $file = $this->apiAs('GET', "/items/{$item}")->assertOk();
        $file->assertJsonPath('has_movements', true)
            ->assertJsonPath('providers.0.last_purchase.unit_price', '12000.000000')
            ->assertJsonPath('providers.0.last_purchase.input_label', 'Frasco x 500');
        $this->assertStringContainsString('0001-00000123', $file->json('providers.0.last_purchase.label'));

        $row = collect($this->apiAs('GET', '/items?search=Ivermectina test')->json())->firstWhere('id', $item);
        $this->assertSame($this->rateId('VAT_10_5'), $row['last_vat_rate_id']);
    }

    public function test_the_critical_level_opens_and_resolves_its_notice_without_a_movement(): void
    {
        [$item] = $this->stockedItem();
        $this->invoice([$this->line($item, '100', '10')])->assertStatus(201);
        $warehouse = $this->apiAs('GET', '/warehouses')->json('0.id');

        $this->apiAs('PATCH', "/items/{$item}/stock/{$warehouse}/critical", ['critical_stock' => '150'])
            ->assertOk()->assertJsonPath('open_incidents.0.type', 'CRITICAL_STOCK');
        $this->apiAs('PATCH', "/items/{$item}/stock/{$warehouse}/critical", ['critical_stock' => '50'])
            ->assertOk()->assertJsonCount(0, 'open_incidents');
        $this->consume([$this->stockLine($item, '60')])->assertStatus(201)->assertJsonPath('incidents_raised.0.type', 'CRITICAL_STOCK');
        $this->apiAs('PATCH', "/items/{$item}/stock/{$warehouse}/critical", ['critical_stock' => null])
            ->assertOk()->assertJsonCount(0, 'open_incidents');
    }

    public function test_a_count_discrepancy_is_resolved_by_hand_and_the_others_are_not(): void
    {
        [$item] = $this->stockedItem();
        $this->consume([$this->stockLine($item, '5')])->assertStatus(201);
        $negative = $this->apiAs('GET', "/items/{$item}/stock")->json('open_incidents.0.id');

        $this->apiAs('PATCH', "/stock-incidents/{$negative}/resolve", ['resolution' => 'x'])->assertStatus(422);

        $this->apiAs('POST', '/item-documents/counts', [
            'item_id' => $item, 'system_quantity_seen' => '-5', 'counted_quantity' => '0', 'input_unit_id' => $this->unitId('ML'),
            'reason' => 'Ajuste', 'occurred_at' => now()->toDateString(), 'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(201);
        $discrepancy = collect($this->apiAs('GET', "/stock-incidents?status=OPEN&item_id={$item}")->json())->firstWhere('type', 'COUNT_DISCREPANCY');

        $this->apiAs('PATCH', "/stock-incidents/{$discrepancy['id']}/resolve", ['resolution' => 'Se había cargado mal el consumo'])
            ->assertOk()->assertJsonPath('status', 'RESOLVED');
    }

    public function test_the_batch_history_adds_cost_per_head_operation_by_operation(): void
    {
        [$item] = $this->stockedItem();
        $visit = $this->serviceItem();
        $batch = $this->ownBatch();
        $this->invoice([$this->line($item, '1000', '24')])->assertStatus(201);
        $this->invoice([$this->line($visit, '1', '60000', null, 'SERVICE', ['batch_id' => $batch->id, 'heads_treated' => 80])])->assertStatus(201);
        $this->consume([$this->stockLine($item, '600')], ['batch_id' => $batch->id, 'heads_treated' => 80])->assertStatus(201);
        $voided = $this->consume([$this->stockLine($item, '100')], ['batch_id' => $batch->id, 'heads_treated' => 100])->assertStatus(201)->json('id');
        $this->apiAs('POST', "/item-documents/{$voided}/reversal", ['reason' => 'Otro lote'])->assertStatus(201);

        $history = $this->apiAs('GET', "/batches/{$batch->id}/consumption-history")->assertOk();

        $history->assertJsonCount(3, 'rows')
            ->assertJsonPath('total_cost', '74400.0000')
            // 60.000 / 80 + 14.400 / 80 = 750 + 180
            ->assertJsonPath('cost_per_head', '930.0000');
        $this->assertNotNull($history->json('rows.0.voided'));
    }

    public function test_a_used_item_freezes_its_unit_and_behaviour_and_keeps_a_balance(): void
    {
        [$item, $bottle] = $this->stockedItem();
        $this->invoice([$this->line($item, '1', '100', $bottle)])->assertStatus(201);

        $this->apiAs('PATCH', "/items/{$item}", ['unit_of_measure_id' => $this->unitId('L')])->assertStatus(422);
        $this->apiAs('PATCH', "/items/{$item}", ['behavior' => 'EXPENSE_ARTICLE'])->assertStatus(422);
        $this->apiAs('PATCH', "/items/{$item}", ['name' => 'Ivermectina renombrada'])->assertOk();
        $this->apiAs('PATCH', "/items/{$item}/active", ['is_active' => false])->assertStatus(422);
        $this->apiAs('PATCH', "/items/{$item}/presentations/{$bottle}", ['factor_to_item_unit' => '250'])->assertStatus(422);
        $this->apiAs('PATCH', "/items/{$item}/presentations/{$bottle}/active", ['is_active' => false])->assertOk();
    }

    public function test_the_reconciliation_finds_no_difference(): void
    {
        [$item] = $this->stockedItem();
        $this->invoice([$this->line($item, '100', '10')])->assertStatus(201);
        $this->consume([$this->stockLine($item, '30')])->assertStatus(201);

        $this->assertSame(0, Artisan::call('stock:reconcile'));
    }
}
