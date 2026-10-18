<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Models\BatchType;
use App\Models\Farm;
use Illuminate\Support\Str;

/**
 * Initial stock, consumption, count and reversal through the ledger (plan 5.2–5.8).
 */
class StockOperationsTest extends StockTestCase
{
    public function test_initial_stock_enters_at_its_declared_cost(): void
    {
        [$salt] = $this->stockedItem('Sal test', 'KG', 'Bolsa x 25', '25');

        $this->apiAs('POST', '/item-documents/initial-stock', [
            'occurred_at' => now()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['item_id' => $salt, 'input_quantity' => '200', 'input_unit_id' => $this->unitId('KG'), 'input_unit_price' => '280']],
        ])->assertStatus(201)->assertJsonPath('type', 'INITIAL')->assertJsonPath('total_cost', '56000.0000');

        $this->assertSame(['quantity' => '200.0000', 'average_unit_cost' => '280.000000'], $this->balance($salt));
    }

    public function test_a_second_entry_moves_the_weighted_average(): void
    {
        [$salt, $bag] = $this->stockedItem('Sal test', 'KG', 'Bolsa x 25', '25');
        $this->apiAs('POST', '/item-documents/initial-stock', [
            'occurred_at' => now()->toDateString(), 'idempotency_key' => (string) Str::uuid(),
            'lines' => [['item_id' => $salt, 'input_quantity' => '200', 'input_unit_id' => $this->unitId('KG'), 'input_unit_price' => '280']],
        ])->assertStatus(201);

        $line = $this->line($salt, '4', '7777', $bag);
        $line['taxes'] = [['tax_id' => $this->taxId('VAT'), 'tax_rate_id' => $this->rateId('VAT_10_5')]];
        $this->invoice([$line])->assertStatus(201)->assertJsonPath('lines.0.unit_cost', '311.080000');

        // (200 x 280 + 100 x 311.08) / 300
        $this->assertSame(['quantity' => '300.0000', 'average_unit_cost' => '290.360000'], $this->balance($salt));
    }

    public function test_a_consumption_leaves_at_the_average_and_is_charged_to_the_batch(): void
    {
        [$item, $bottle] = $this->stockedItem();
        $this->invoice([$this->line($item, '10', '12000', $bottle)])->assertStatus(201);
        $batch = $this->ownBatch();

        $this->consume([$this->stockLine($item, '600')], ['batch_id' => $batch->id, 'heads_treated' => 80])
            ->assertStatus(201)
            ->assertJsonPath('lines.0.unit_cost', '24.000000')
            ->assertJsonPath('total_cost', '14400.0000')
            ->assertJsonPath('batch.id', $batch->id);

        $this->assertSame('4400.0000', $this->balance($item)['quantity']);
    }

    public function test_a_negative_balance_is_recorded_and_raises_a_notice_that_resolves_itself(): void
    {
        [$item] = $this->stockedItem();

        $this->consume([$this->stockLine($item, '25')])
            ->assertStatus(201)
            ->assertJsonPath('incidents_raised.0.type', 'NEGATIVE_BALANCE');
        $this->assertSame('-25.0000', $this->balance($item)['quantity']);
        $this->apiAs('GET', "/items/{$item}/stock")->assertJsonPath('open_incidents.0.type', 'NEGATIVE_BALANCE');

        $this->invoice([$this->line($item, '100', '10')])->assertStatus(201);
        $this->apiAs('GET', "/items/{$item}/stock")->assertJsonCount(0, 'open_incidents');
        // Entering over a negative balance takes the cost of the entry (logical schema 5.4).
        $this->assertSame(['quantity' => '75.0000', 'average_unit_cost' => '10.000000'], $this->balance($item));
    }

    public function test_external_dead_and_foreign_batches_do_not_receive_a_consumption(): void
    {
        [$item] = $this->stockedItem();
        $external = $this->ownBatch();
        $external->update(['farm_id' => Farm::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'provider_id' => $this->seller->id, 'name' => 'Campo test ' . Str::random(4),
        ])->id]);
        $dead = $this->ownBatch();
        $dead->update(['batch_type_id' => BatchType::query()->where('code', 'INTERNAL_DEATH')->value('id')]);

        $this->consume([$this->stockLine($item, '1')], ['batch_id' => $external->id, 'heads_treated' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('batch_id');
        $this->consume([$this->stockLine($item, '1')], ['batch_id' => $dead->id, 'heads_treated' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('batch_id');
        $foreign = $this->asOther(fn () => $this->ownBatchOfOther());
        $this->consume([$this->stockLine($item, '1')], ['batch_id' => $foreign, 'heads_treated' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('batch_id');
    }

    public function test_services_and_repeated_items_are_refused_in_a_consumption(): void
    {
        [$item] = $this->stockedItem();
        $visit = $this->serviceItem();

        $this->consume([$this->stockLine($visit, '1', 'SERVICE'), $this->stockLine($item, '1'), $this->stockLine($item, '2')])
            ->assertStatus(422)->assertJsonValidationErrors(['lines.0.item_id', 'lines.2.item_id']);
    }

    public function test_a_count_adjusts_the_difference_at_the_average(): void
    {
        [$item, $bottle] = $this->stockedItem();
        $this->invoice([$this->line($item, '10', '12000', $bottle)])->assertStatus(201);

        $this->countStock($item, '5000', '4500', 'Frasco roto')
            ->assertStatus(201)
            ->assertJsonPath('lines.0.quantity', '500.0000')
            ->assertJsonPath('lines.0.counted_quantity', '4500.0000')
            ->assertJsonPath('lines.0.movement.direction', -1)
            ->assertJsonPath('incidents_raised.0.type', 'COUNT_DISCREPANCY');
        $this->assertSame('4500.0000', $this->balance($item)['quantity']);
    }

    public function test_a_count_without_difference_keeps_its_line_and_moves_nothing(): void
    {
        [$item] = $this->stockedItem();
        $this->invoice([$this->line($item, '100', '10')])->assertStatus(201);

        $this->countStock($item, '100', '100')
            ->assertStatus(201)
            ->assertJsonPath('lines.0.quantity', '0.0000')
            ->assertJsonPath('lines.0.movement', null);
    }

    public function test_a_count_with_difference_needs_a_reason_and_the_balance_the_operator_saw(): void
    {
        [$item] = $this->stockedItem();
        $this->invoice([$this->line($item, '100', '10')])->assertStatus(201);

        $this->countStock($item, '100', '90')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->countStock($item, '80', '90', 'x')->assertStatus(409)->assertJsonPath('current_quantity', '100.0000');
    }

    public function test_a_count_may_be_declared_in_a_presentation_and_be_zero(): void
    {
        [$item, $bottle] = $this->stockedItem();
        $this->invoice([$this->line($item, '2', '1000', $bottle)])->assertStatus(201);

        $this->countStock($item, '1000', '1', 'Se usó uno', $bottle)->assertStatus(201)->assertJsonPath('lines.0.counted_quantity', '500.0000');
        $this->countStock($item, '500', '0', 'No queda nada')->assertStatus(201);
        $this->assertSame('0.0000', $this->balance($item)['quantity']);
    }

    public function test_reversing_a_consumption_brings_the_goods_back_at_their_cost(): void
    {
        [$item] = $this->stockedItem();
        $this->invoice([$this->line($item, '100', '10')])->assertStatus(201);
        $consumption = $this->consume([$this->stockLine($item, '30')])->assertStatus(201)->json('id');

        $this->apiAs('POST', "/item-documents/{$consumption}/reversal", ['reason' => 'Era otro lote'])
            ->assertStatus(201)->assertJsonPath('type', 'REVERSAL')->assertJsonPath('reverses.id', $consumption);
        $this->assertSame(['quantity' => '100.0000', 'average_unit_cost' => '10.000000'], $this->balance($item));

        $this->apiAs('POST', "/item-documents/{$consumption}/reversal", ['reason' => 'Otra vez'])->assertStatus(422);
        $reversal = $this->apiAs('GET', "/item-documents/{$consumption}")->json('reversed_by.id');
        $this->apiAs('POST', "/item-documents/{$reversal}/reversal", ['reason' => 'x'])->assertStatus(422);
    }

    public function test_the_kardex_follows_the_load_order_not_the_declared_date(): void
    {
        [$item] = $this->stockedItem();
        $this->invoice([$this->line($item, '100', '10')], ['issued_at' => now()->subDays(2)->toDateString()])->assertStatus(201);
        $this->consume([$this->stockLine($item, '10')], ['occurred_at' => now()->subDays(10)->toDateString()])->assertStatus(201);

        $kardex = $this->apiAs('GET', "/items/{$item}/kardex")->assertOk();
        $kardex->assertJsonPath('data.0.document_type', 'CONSUMPTION')->assertJsonPath('data.0.balance_after', '90.0000');
    }

    public function test_numbers_are_correlative_per_company(): void
    {
        [$item] = $this->stockedItem();
        $first = $this->consume([$this->stockLine($item, '1')])->assertStatus(201)->json('number');
        $second = $this->consume([$this->stockLine($item, '1')])->assertStatus(201)->json('number');

        $this->assertSame($first + 1, $second);
    }

    private function countStock(int $item, string $seen, string $counted, ?string $reason = null, ?int $presentation = null): \Illuminate\Testing\TestResponse
    {
        return $this->apiAs('POST', '/item-documents/counts', [
            'item_id' => $item,
            'system_quantity_seen' => $seen,
            'counted_quantity' => $counted,
            'input_unit_id' => $presentation === null ? $this->unitId('ML') : null,
            'input_presentation_id' => $presentation,
            'reason' => $reason,
            'occurred_at' => now()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function ownBatchOfOther(): int
    {
        return (int) \App\Models\Batch::withoutGlobalScopes()->create([
            'company_id' => $this->other->id, 'name' => 'Ajeno ' . Str::random(4), 'is_active' => true,
            'batch_type_id' => BatchType::query()->where('code', 'OPERATIONAL')->value('id'),
        ])->id;
    }
}
