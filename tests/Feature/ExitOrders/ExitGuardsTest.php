<?php

declare(strict_types=1);

namespace Tests\Feature\ExitOrders;

use App\Core\Enums\PartnerRole;
use App\Models\Batch;
use App\Models\Company;
use App\Models\DeathCause;
use App\Models\Farm;
use Tests\Support\TestPartner;

/**
 * Who may leave and to whom (E2–E6, E8): alive, own, outside terminal batches, not held by another
 * order, sold to an active customer of the company; one row that fails leaves the load unwritten.
 */
class ExitGuardsTest extends ExitOrderTestCase
{
    public function test_an_animal_pending_in_an_exit_order_is_held_by_it(): void
    {
        $steer = $this->steer('EX-G1');
        $code = $this->issue([$steer])->assertStatus(201)->json('code');

        $this->issue([$steer])->assertStatus(422)->assertJsonValidationErrors('caravan_ids.0');
        $this->registerExit([$this->line($steer)])->assertStatus(422)->assertJsonValidationErrors('lines.0.caravan_id');

        $cause = DeathCause::where('company_id', $this->company->id)->firstOrFail();
        $errors = $this->apiAs('POST', '/caravans/deaths', ['caravan_ids' => [$steer->id], 'death_date' => now()->toDateString(), 'death_cause_id' => $cause->id])
            ->assertStatus(422)->json('errors');
        $this->assertSame("EX-G1 está en la orden {$code}: registrá su muerte desde esa orden.", $errors['caravan_ids.0'][0]);
    }

    public function test_dead_external_or_already_exited_animals_do_not_leave(): void
    {
        $dead = $this->steer('EX-G2');
        $cause = DeathCause::where('company_id', $this->company->id)->firstOrFail();
        $this->apiAs('POST', '/caravans/deaths', ['caravan_ids' => [$dead->id], 'death_date' => now()->toDateString(), 'death_cause_id' => $cause->id])->assertStatus(201);

        $seller = $this->partner('Vendedor de Prueba', [PartnerRole::SUPPLIER_LIVESTOCK]);
        $farm = Farm::create(['company_id' => $this->company->id, 'provider_id' => $seller->id, 'name' => 'Campo externo', 'is_active' => true]);
        $external = $this->steer('EX-G3', Batch::create(['company_id' => $this->company->id, 'name' => 'Lote externo', 'farm_id' => $farm->id, 'is_active' => true]));

        $sold = $this->steer('EX-G4');
        $this->registerExit([$this->line($sold)])->assertStatus(201);

        $errors = $this->registerExit([$this->line($dead), $this->line($external), $this->line($sold)])
            ->assertStatus(422)->json('errors');

        $this->assertArrayHasKey('lines.0.caravan_id', $errors);
        $this->assertArrayHasKey('lines.1.caravan_id', $errors);
        $this->assertArrayHasKey('lines.2.caravan_id', $errors);
    }

    public function test_one_row_that_fails_leaves_the_whole_load_unwritten(): void
    {
        $ok = $this->steer('EX-G5');
        $future = $this->steer('EX-G6');

        $this->registerExit([$this->line($ok), $this->line($future)], ['exit_date' => now()->addDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors(['lines.0.caravan_id', 'lines.1.caravan_id']);

        $this->assertSame($this->breedingA->id, (int) $ok->fresh()->batch_id);
    }

    public function test_the_buyer_is_an_active_customer_of_the_company(): void
    {
        $steer = $this->steer('EX-G7');
        $supplierOnly = $this->partner('Sólo proveedor', [PartnerRole::SUPPLIER_LIVESTOCK]);
        $inactive = $this->partner('Cliente inactivo', [PartnerRole::CUSTOMER_LIVESTOCK]);
        $inactive->update(['is_active' => false]);
        $other = Company::where('id', '!=', $this->company->id)->first() ?? Company::create(['name' => 'Otra empresa EX']);
        $foreign = $this->partner('Cliente ajeno', [PartnerRole::CUSTOMER_LIVESTOCK], $other->id);

        foreach ([$supplierOnly, $inactive, $foreign] as $buyer) {
            $this->issue([$steer], ['buyer_partner_id' => $buyer->id])
                ->assertStatus(422)->assertJsonValidationErrors('buyer_partner_id');
        }
    }

    public function test_the_quick_transfer_neither_sells_nor_brings_back_a_sold_animal(): void
    {
        $sold = $this->steer('EX-G8');
        $this->registerExit([$this->line($sold)])->assertStatus(201);
        $exitBatchId = (int) $sold->fresh()->batch_id;

        $this->apiAs('POST', '/caravans/bulk-transfer', ['caravan_ids' => [$this->steer('EX-G9')->id], 'target_batch_id' => $exitBatchId])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Para registrar una venta usá «Vender» o una orden de egreso: guarda el comprador y el DTE.');

        $this->apiAs('POST', '/caravans/bulk-transfer', ['caravan_ids' => [$sold->id], 'target_batch_id' => $this->breedingB->id])
            ->assertStatus(422);
        $this->assertSame($exitBatchId, (int) $sold->fresh()->batch_id);
    }

    public function test_a_buyer_with_exit_orders_keeps_buying_livestock(): void
    {
        $this->registerExit([$this->line($this->steer('EX-G10'))])->assertStatus(201);

        $this->apiAs('PATCH', "/partners/{$this->buyer->id}/roles", ['roles' => ['SUPPLIER_LIVESTOCK']])
            ->assertStatus(422)->assertJsonValidationErrors('roles');
        $this->apiAs('PATCH', "/partners/{$this->buyer->id}/roles", ['roles' => ['CUSTOMER_LIVESTOCK', 'CUSTOMER_ITEMS']])
            ->assertOk();
    }
}
