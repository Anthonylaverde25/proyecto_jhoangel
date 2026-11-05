<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Core\ValueObjects\Decimal;
use App\Models\Batch;
use App\Models\Caravan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A consumption of one article to one batch on behalf of an AI client: the preview computes what would
 * happen and hands out one confirmation per option; the registration records the option confirmed.
 */
class AiConsumptionTest extends StockTestCase
{
    private const PREVIEW = '/item-documents/ai-consumptions/preview';
    private const STORE = '/item-documents/ai-consumptions';

    private int $item;
    private Batch $batch;
    private int $main;
    private int $shed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->item = $this->createItem(['name' => 'Rollo test', 'unit_of_measure_id' => $this->unitId('UNIT')]);
        $this->batch = $this->ownBatch();
        $this->main = (int) DB::table('warehouses')->where('company_id', $this->company->id)->where('is_default', true)->where('kind', 'GENERAL')->value('id');
        $this->shed = (int) $this->apiAs('POST', '/warehouses', ['code' => 'TST-02', 'name' => 'Galpón'])->assertStatus(201)->json('id');
    }

    public function test_the_default_warehouse_covers_it_and_there_is_nothing_to_choose(): void
    {
        $this->stockIn($this->main, '5', '100');
        $this->stockIn($this->shed, '5', '100');

        $preview = $this->preview('3', ['heads_treated' => 10])->assertOk()
            ->assertJsonPath('data.can_register', true)
            ->assertJsonPath('data.needs_choice', false)
            ->assertJsonPath('data.options.0.code', 'BASE')
            ->assertJsonPath('data.options.0.warehouse.id', $this->main)
            ->assertJsonPath('data.options.0.balance_after', '2.0000')
            ->assertJsonPath('data.options.0.goes_negative', false)
            ->assertJsonPath('data.options.0.cost.estimated_total', '300.0000')
            ->assertJsonPath('data.options.0.cost.estimated_per_head', '30.0000');

        $this->register($preview, 0)->assertCreated()
            ->assertJsonPath('data.type', 'CONSUMPTION')
            ->assertJsonPath('data.source', 'AI')
            ->assertJsonPath('data.batch.id', $this->batch->id)
            ->assertJsonPath('data.heads_treated', 10)
            ->assertJsonPath('data.total_cost', '300.0000')
            ->assertJsonPath('data.stock_after.0.quantity', '2.0000');
    }

    public function test_your_example_offers_the_three_ways_and_each_leaves_the_balances_it_said(): void
    {
        $this->stockIn($this->main, '1', '100');
        $this->stockIn($this->shed, '3', '100');

        $preview = $this->preview('3', ['heads_treated' => 10])->assertJsonPath('data.needs_choice', true);
        $this->assertSame(['BASE', 'COMPLETE', 'OTHER'], array_column($preview->json('data.options'), 'code'));

        $preview->assertJsonPath('data.options.0.balance_after', '-2.0000')
            ->assertJsonPath('data.options.0.goes_negative', true)
            ->assertJsonPath('data.options.1.warehouse.id', $this->main)
            ->assertJsonPath('data.options.1.transfers.0.from_warehouse_id', $this->shed)
            ->assertJsonPath('data.options.1.transfers.0.quantity', '2.0000')
            ->assertJsonPath('data.options.1.transfers.0.balance_after', '1.0000')
            ->assertJsonPath('data.options.1.balance_after', '0.0000')
            ->assertJsonPath('data.options.2.warehouse.id', $this->shed)
            ->assertJsonPath('data.options.2.balance_after', '0.0000');
    }

    public function test_going_negative_is_allowed_and_raises_the_notice(): void
    {
        $this->stockIn($this->main, '1', '100');
        $this->stockIn($this->shed, '3', '100');
        $preview = $this->preview('3', ['heads_treated' => 10]);

        $this->register($preview, 0)->assertCreated()
            ->assertJsonPath('data.incidents_raised.0.type', 'NEGATIVE_BALANCE');

        $this->assertBalances(['-2.0000', '3.0000']);
    }

    public function test_completing_transfers_first_and_records_one_consumption(): void
    {
        $this->stockIn($this->main, '1', '100');
        $this->stockIn($this->shed, '3', '100');
        $preview = $this->preview('3', ['heads_treated' => 10]);

        $response = $this->register($preview, 1)->assertCreated()
            ->assertJsonPath('data.warehouse.id', $this->main)
            ->assertJsonPath('data.source', 'AI')
            ->assertJsonPath('data.transfers.0.quantity', '2.0000')
            ->assertJsonCount(0, 'data.incidents_raised');

        $this->assertBalances(['0.0000', '1.0000']);

        $number = $response->json('data.number');
        $transfer = $this->operationsQuery('TRANSFER_OUT')->first();
        $this->assertSame('AI', $transfer->source);
        $this->assertSame("Completa el consumo N.º {$number}.", $transfer->notes);
        $this->assertSame("Completa el consumo N.º {$number}.", $this->operationsQuery('TRANSFER_IN')->value('notes'));
        $this->assertStringContainsString("transferencia N.º {$transfer->number}", (string) $response->json('data.notes'));
        $this->assertSame(1, $this->operations('CONSUMPTION'));
    }

    public function test_taking_it_all_from_the_other_warehouse_leaves_the_default_alone(): void
    {
        $this->stockIn($this->main, '1', '100');
        $this->stockIn($this->shed, '3', '100');
        $preview = $this->preview('3', ['heads_treated' => 10]);

        $this->register($preview, 2)->assertCreated()->assertJsonPath('data.warehouse.id', $this->shed);

        $this->assertBalances(['1.0000', '0.0000']);
    }

    public function test_when_no_warehouse_holds_it_the_only_way_is_the_default_going_negative(): void
    {
        $preview = $this->preview('10', ['heads_treated' => 10])
            ->assertJsonPath('data.needs_choice', false)
            ->assertJsonCount(1, 'data.options')
            ->assertJsonPath('data.options.0.warehouse.id', $this->main)
            ->assertJsonPath('data.options.0.balance_after', '-10.0000')
            ->assertJsonPath('data.options.0.goes_negative', true)
            ->assertJsonPath('data.options.0.cost.known', false)
            ->assertJsonPath('data.options.0.cost.estimated_total', null);

        $this->register($preview, 0)->assertCreated()->assertJsonPath('data.incidents_raised.0.type', 'NEGATIVE_BALANCE');
    }

    public function test_when_together_they_do_not_reach_completing_still_leaves_the_rest_negative(): void
    {
        $this->stockIn($this->main, '1', '100');
        $this->stockIn($this->shed, '1', '100');

        $preview = $this->preview('3', ['heads_treated' => 10]);

        $this->assertSame(['BASE', 'COMPLETE'], array_column($preview->json('data.options'), 'code'));
        $preview->assertJsonPath('data.options.1.transfers.0.quantity', '1.0000')
            ->assertJsonPath('data.options.1.balance_after', '-1.0000');
    }

    public function test_a_named_warehouse_goes_first(): void
    {
        $this->stockIn($this->main, '5', '100');
        $this->stockIn($this->shed, '5', '100');

        $this->preview('3', ['heads_treated' => 10, 'warehouse_id' => $this->shed])
            ->assertJsonPath('data.needs_choice', false)
            ->assertJsonPath('data.options.0.warehouse.id', $this->shed);
    }

    public function test_an_inactive_warehouse_is_not_offered(): void
    {
        $this->stockIn($this->shed, '5', '100');
        $this->apiAs('PATCH', "/warehouses/{$this->shed}/active", ['is_active' => false]);

        $this->preview('3', ['heads_treated' => 10])
            ->assertJsonPath('data.needs_choice', false)
            ->assertJsonCount(1, 'data.options');
    }

    public function test_units_without_invoice_can_be_consumed_but_not_transferred(): void
    {
        $this->stockIn($this->shed, '1', '100');
        // A delivery note without prices always enters the default warehouse.
        $this->apiAs('POST', '/fiscal-documents', [
            'provider_id' => $this->seller->id,
            'fiscal_document_type_id' => $this->typeId('DELIVERY_NOTE'),
            'point_of_sale' => 1,
            'number' => random_int(1, 99999999),
            'issued_at' => now()->subDays(3)->toDateString(),
            'is_valued' => false,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['item_id' => $this->item, 'input_quantity' => '5', 'input_unit_id' => $this->unitId('UNIT')]],
        ])->assertStatus(201);

        $preview = $this->preview('3', ['heads_treated' => 10, 'warehouse_id' => $this->shed]);

        // Nothing valued to transfer, so completing is not offered; taking them all from there is.
        $this->assertSame(['BASE', 'OTHER'], array_column($preview->json('data.options'), 'code'));
        $preview->assertJsonPath('data.options.1.warehouse.id', $this->main)
            ->assertJsonPath('data.options.1.cost.pending_quantity', '3.0000')
            ->assertJsonPath('data.options.1.cost.known', true);
    }

    public function test_a_presentation_is_converted_by_the_system(): void
    {
        [$salt, $bag] = $this->stockedItem('Sal test', 'KG', 'Bolsa x 25', '25');
        $this->stockIn($this->main, '100', '10', $salt, 'KG');

        $this->apiAs('GET', self::PREVIEW . '?' . http_build_query([
            'item_id' => $salt, 'batch_id' => $this->batch->id, 'input_quantity' => '2', 'input_presentation_id' => $bag,
            'occurred_at' => now()->toDateString(), 'heads_treated' => 10,
        ]))->assertJsonPath('data.quantity', '50.0000')
            ->assertJsonPath('data.input_label', '2 Bolsa x 25')
            ->assertJsonPath('data.options.0.balance_after', '50.0000');
    }

    public function test_heads_default_to_the_batch_and_are_said_to_come_from_it(): void
    {
        Caravan::create(['company_id' => $this->company->id, 'identification' => 'AI-C-1', 'sex' => 'M', 'teeth' => 0, 'batch_id' => $this->batch->id]);
        DB::table('batches')->where('id', $this->batch->id)->update(['caravans_count' => 1]);

        $this->preview('1')->assertJsonPath('data.heads_treated', 1)->assertJsonPath('data.heads_source', 'BATCH_CURRENT');
    }

    public function test_a_batch_without_heads_needs_them_declared(): void
    {
        $this->preview('1')
            ->assertOk()
            ->assertJsonPath('data.can_register', false)
            ->assertJsonPath('data.blockers.0', 'Indicá cuántas cabezas lo recibieron.');
    }

    public function test_more_heads_than_the_batch_is_a_warning_that_can_be_accepted(): void
    {
        $this->stockIn($this->main, '5', '100');
        $preview = $this->preview('1', ['heads_treated' => 10]);
        $preview->assertJsonCount(1, 'data.warnings');

        $this->register($preview, 0, ['acknowledge_warnings' => false])->assertStatus(422)->assertJsonPath('code', 'CONSUMPTION_WARNINGS');
        $this->assertSame(0, $this->operations('CONSUMPTION'));

        $this->register($preview, 0)->assertCreated();
    }

    public function test_the_same_consumption_that_day_is_a_warning(): void
    {
        $this->stockIn($this->main, '5', '100');
        DB::table('batches')->where('id', $this->batch->id)->update(['caravans_count' => 10]);
        $this->register($this->preview('1'), 0)->assertCreated();

        $second = $this->preview('1');
        $this->assertStringContainsString('Ya hay un consumo igual', $second->json('data.warnings.0'));

        $this->register($second, 0, ['acknowledge_warnings' => false])->assertStatus(422);
        $this->register($second, 0)->assertCreated();
        $this->assertSame('3.0000', $this->quantityIn($this->main));
    }

    public function test_a_voided_consumption_does_not_warn(): void
    {
        $this->stockIn($this->main, '5', '100');
        DB::table('batches')->where('id', $this->batch->id)->update(['caravans_count' => 10]);
        $first = $this->register($this->preview('1'), 0)->assertCreated();
        $this->apiAs('POST', '/item-documents/' . $first->json('data.id') . '/reversal', ['reason' => 'Error'])->assertCreated();

        $this->preview('1')->assertJsonCount(0, 'data.warnings');
    }

    public function test_what_cannot_be_consumed_says_why(): void
    {
        $service = $this->serviceItem();
        $expense = $this->createItem(['name' => 'Gasto test', 'behavior' => 'EXPENSE_ARTICLE', 'unit_of_measure_id' => $this->unitId('UNIT')]);
        $inactive = $this->createItem(['name' => 'Baja test', 'unit_of_measure_id' => $this->unitId('UNIT')]);
        DB::table('items')->where('id', $inactive)->update(['is_active' => false]);

        foreach ([[$service, 'es un servicio'], [$expense, 'no lleva stock'], [$inactive, 'está desactivado'], [999999, 'no existe']] as [$id, $reason]) {
            $response = $this->apiAs('GET', self::PREVIEW . '?' . http_build_query([
                'item_id' => $id, 'batch_id' => $this->batch->id, 'input_quantity' => '1', 'input_unit_id' => $this->unitId('UNIT'),
                'occurred_at' => now()->toDateString(), 'heads_treated' => 1,
            ]))->assertOk()->assertJsonPath('data.can_register', false)->assertJsonCount(0, 'data.options');
            $this->assertStringContainsString($reason, $response->json('data.blockers.0'));
        }
    }

    public function test_a_future_date_and_a_closed_batch_are_blocked(): void
    {
        $this->preview('1', ['heads_treated' => 1, 'occurred_at' => now()->addDays(2)->toDateString()])
            ->assertJsonPath('data.blockers.0', 'La fecha no puede ser futura.');

        DB::table('batches')->where('id', $this->batch->id)->update(['is_active' => false]);
        $this->assertStringContainsString('está cerrado', $this->preview('1', ['heads_treated' => 1])->json('data.blockers.0'));
    }

    public function test_a_retry_with_the_same_confirmation_writes_nothing_more(): void
    {
        $this->stockIn($this->main, '5', '100');
        $preview = $this->preview('2', ['heads_treated' => 10]);

        $this->register($preview, 0)->assertCreated()->assertJsonPath('data.replayed', false);
        $this->register($preview, 0)->assertOk()->assertJsonPath('data.replayed', true);

        $this->assertSame(1, $this->operations('CONSUMPTION'));
        $this->assertSame('3.0000', $this->quantityIn($this->main));
    }

    public function test_a_retry_of_a_completion_does_not_transfer_twice_even_after_it_expired(): void
    {
        $this->stockIn($this->main, '1', '100');
        $this->stockIn($this->shed, '3', '100');
        $preview = $this->preview('3', ['heads_treated' => 10]);
        $this->register($preview, 1)->assertCreated();

        $this->travel(2)->hours();
        $this->register($preview, 1)->assertOk()->assertJsonPath('data.replayed', true);

        $this->assertSame(1, $this->operations('TRANSFER_OUT'));
        $this->assertBalances(['0.0000', '1.0000']);
    }

    public function test_the_confirmation_is_tied_to_what_the_user_saw(): void
    {
        $this->stockIn($this->main, '5', '100');
        $preview = $this->preview('2', ['heads_treated' => 10]);

        foreach (['input_quantity' => '3', 'heads_treated' => 11, 'warehouse_id' => $this->shed, 'occurred_at' => now()->subDay()->toDateString()] as $field => $value) {
            $this->register($preview, 0, [$field => $value])->assertStatus(422)->assertJsonPath('code', 'CONFIRMATION_INVALID');
        }
        $this->register($preview, 0, ['confirmation_token' => 'cualquiera'])->assertStatus(422)->assertJsonPath('code', 'CONFIRMATION_INVALID');

        $this->assertSame(0, $this->operations('CONSUMPTION'));
    }

    public function test_an_option_cannot_be_used_with_the_token_of_another(): void
    {
        $this->stockIn($this->main, '1', '100');
        $this->stockIn($this->shed, '3', '100');
        $preview = $this->preview('3', ['heads_treated' => 10]);

        $this->register($preview, 1, ['confirmation_token' => $preview->json('data.options.0.confirmation_token')])
            ->assertStatus(422)->assertJsonPath('code', 'CONFIRMATION_INVALID');
    }

    public function test_an_expired_confirmation_is_refused(): void
    {
        $this->stockIn($this->main, '5', '100');
        $preview = $this->preview('2', ['heads_treated' => 10]);

        $this->travel(31)->minutes();

        $this->register($preview, 0)->assertStatus(422)->assertJsonPath('code', 'CONFIRMATION_EXPIRED');
    }

    public function test_a_transfer_that_no_longer_fits_leaves_nothing_behind(): void
    {
        $this->stockIn($this->main, '1', '100');
        $this->stockIn($this->shed, '3', '100');
        $preview = $this->preview('3', ['heads_treated' => 10]);
        $this->consume([$this->stockLine($this->item, '3', 'UNIT')], ['warehouse_id' => $this->shed])->assertStatus(201);

        $this->register($preview, 1)->assertStatus(422);

        $this->assertSame(0, $this->operations('TRANSFER_OUT'));
        $this->assertSame(1, $this->operations('CONSUMPTION'));
        $this->assertBalances(['1.0000', '0.0000']);
    }

    public function test_the_screen_still_records_a_negative_balance_and_declares_no_origin(): void
    {
        $this->consume([$this->stockLine($this->item, '3', 'UNIT')])
            ->assertStatus(201)
            ->assertJsonPath('source', null)
            ->assertJsonPath('incidents_raised.0.type', 'NEGATIVE_BALANCE');
    }

    public function test_the_origin_cannot_be_set_from_the_screen_route(): void
    {
        $this->consume([$this->stockLine($this->item, '3', 'UNIT')], ['source' => 'AI'])
            ->assertStatus(201)
            ->assertJsonPath('source', null);
    }

    /** @param array<string, mixed> $extra */
    private function preview(string $quantity, array $extra = []): TestResponse
    {
        return $this->apiAs('GET', self::PREVIEW . '?' . http_build_query($extra + [
            'item_id' => $this->item,
            'batch_id' => $this->batch->id,
            'input_quantity' => $quantity,
            'input_unit_id' => $this->unitId('UNIT'),
            'occurred_at' => now()->toDateString(),
        ]));
    }

    /** @param array<string, mixed> $overrides */
    private function register(TestResponse $preview, int $option, array $overrides = []): TestResponse
    {
        $data = $preview->json('data');
        $chosen = $data['options'][$option];

        return $this->apiAs('POST', self::STORE, $overrides + [
            'item_id' => $data['item']['id'],
            'batch_id' => $data['batch']['id'],
            'warehouse_id' => $chosen['warehouse']['id'],
            'input_quantity' => $data['input_quantity'],
            'input_unit_id' => $data['input_unit_id'],
            'input_presentation_id' => $data['input_presentation_id'],
            'heads_treated' => $data['heads_treated'],
            'occurred_at' => $data['occurred_at'],
            'transfers' => array_map(fn (array $t) => ['from_warehouse_id' => $t['from_warehouse_id'], 'quantity' => $t['quantity']], $chosen['transfers']),
            'confirmation_token' => $chosen['confirmation_token'],
            'acknowledge_warnings' => true,
        ]);
    }

    private function stockIn(int $warehouseId, string $quantity, string $price, ?int $itemId = null, string $unit = 'UNIT'): void
    {
        $this->apiAs('POST', '/item-documents/initial-stock', [
            'occurred_at' => now()->subDays(5)->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'warehouse_id' => $warehouseId,
            'lines' => [['item_id' => $itemId ?? $this->item, 'input_quantity' => $quantity, 'input_unit_id' => $this->unitId($unit), 'input_unit_price' => $price]],
        ])->assertStatus(201);
    }

    /** Tests run over the seeded examples: only this test's item counts. */
    private function operationsQuery(string $type): \Illuminate\Database\Query\Builder
    {
        return DB::table('item_documents')
            ->where('type', $type)
            ->whereIn('id', DB::table('item_document_lines')->where('item_id', $this->item)->select('item_document_id'));
    }

    private function operations(string $type): int
    {
        return $this->operationsQuery($type)->count();
    }

    /** @param array{0: string, 1: string} $expected main, then shed */
    private function assertBalances(array $expected): void
    {
        $this->assertSame($expected, [$this->quantityIn($this->main), $this->quantityIn($this->shed)]);
    }

    /** SQLite hands the column back as a number; MySQL, as a string. */
    private function quantityIn(int $warehouseId): string
    {
        return Decimal::of((string) DB::table('item_stocks')->where('item_id', $this->item)->where('warehouse_id', $warehouseId)->value('quantity'))->toScale(4);
    }
}
