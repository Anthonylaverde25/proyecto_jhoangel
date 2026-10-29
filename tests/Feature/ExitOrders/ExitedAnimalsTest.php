<?php

declare(strict_types=1);

namespace Tests\Feature\ExitOrders;

use App\Core\Enums\PartnerRole;
use App\Models\Company;

/**
 * GET /exit-orders/animals: the animals that already left, one flat row each across orders, with
 * the category they left with, their kg and their $/kg. What is still pending is not a sale yet.
 */
class ExitedAnimalsTest extends ExitOrderTestCase
{
    public function test_each_exited_animal_is_a_row_with_its_snapshot_and_price(): void
    {
        $steer = $this->steer('EX-A1');
        $heifer = $this->pregnantFemale('EX-A2');

        $code = $this->registerExit([
            $this->line($steer, ['weight_kg' => 400, 'price_per_kg' => 2000]),
            $this->line($heifer, ['weight_kg' => 350.5, 'weight_estimated' => true, 'price_per_kg' => 1800]),
        ])->assertStatus(201)->json('code');

        $rows = collect($this->apiAs('GET', '/exit-orders/animals')->assertOk()->json())->keyBy('caravan_id');

        $this->assertCount(2, $rows);

        $steerRow = $rows[$steer->id];
        $this->assertSame($code, $steerRow['exit_order']['code']);
        $this->assertSame('REGISTERED', $steerRow['exit_order']['kind']);
        $this->assertSame(now()->toDateString(), $steerRow['exit_date']);
        $this->assertSame('Frigorífico de Prueba', $steerRow['buyer']['name']);
        $this->assertSame('EX-A1', $steerRow['label']);
        $this->assertSame($this->categoryId('NOVILLO'), $steerRow['category']['id']);
        $this->assertSame($this->breedingA->id, $steerRow['from_batch']['id']);
        $this->assertSame($this->activityId('CRIA'), $steerRow['activity']['id']);
        $this->assertEqualsWithDelta(400, $steerRow['weight_kg'], 0.001);
        $this->assertFalse($steerRow['weight_estimated']);
        $this->assertEqualsWithDelta(2000, $steerRow['price_per_kg'], 0.001);
        $this->assertEqualsWithDelta(800000, $steerRow['amount'], 0.001);

        $heiferRow = $rows[$heifer->id];
        $this->assertSame($this->categoryId('VAQUILLONA'), $heiferRow['category']['id']);
        $this->assertTrue($heiferRow['was_pregnant']);
        $this->assertTrue($heiferRow['weight_estimated']);
        $this->assertEqualsWithDelta(630900, $heiferRow['amount'], 0.001);
    }

    public function test_pending_animals_of_an_issued_order_are_not_listed_until_they_leave(): void
    {
        $first = $this->steer('EX-A3');
        $second = $this->steer('EX-A4');

        $orderId = $this->issue([$first, $second], [
            'agreed_prices' => [['caravan_id' => $first->id, 'price_per_kg' => 2100], ['caravan_id' => $second->id, 'price_per_kg' => 2100]],
        ])->assertStatus(201)->json('id');

        $this->apiAs('GET', '/exit-orders/animals')->assertOk()->assertJsonCount(0);

        $this->execute($orderId, [$this->line($first, ['weight_kg' => 420, 'price_per_kg' => 2100])])->assertOk();

        $this->apiAs('GET', '/exit-orders/animals?exit_order_id=' . $orderId)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.caravan_id', $first->id)
            ->assertJsonPath('0.exit_order.kind', 'PLANNED');
    }

    public function test_filters_by_category_buyer_and_exit_date(): void
    {
        $steer = $this->steer('EX-A5');
        $heifer = $this->pregnantFemale('EX-A6');
        $otherBuyer = $this->partner('Consignataria Norte', [PartnerRole::CUSTOMER_LIVESTOCK]);
        $lastMonth = now()->subMonth()->toDateString();

        $this->registerExit([$this->line($steer, ['weight_kg' => 410, 'price_per_kg' => 2000])])->assertStatus(201);
        $this->registerExit([$this->line($heifer)], ['buyer_partner_id' => $otherBuyer->id, 'exit_date' => $lastMonth])->assertStatus(201);

        $this->apiAs('GET', '/exit-orders/animals?category_id=' . $this->categoryId('NOVILLO'))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.caravan_id', $steer->id);

        $this->apiAs('GET', '/exit-orders/animals?buyer_partner_id=' . $otherBuyer->id)
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.caravan_id', $heifer->id)
            ->assertJsonPath('0.exit_date', $lastMonth)
            ->assertJsonPath('0.weight_kg', null)
            ->assertJsonPath('0.price_per_kg', null);

        $this->apiAs('GET', '/exit-orders/animals?from=' . now()->subDays(7)->toDateString())
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.caravan_id', $steer->id);

        $this->apiAs('GET', '/exit-orders/animals?to=' . now()->subDays(7)->toDateString())
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.caravan_id', $heifer->id);
    }

    public function test_another_company_does_not_see_the_exits(): void
    {
        $this->registerExit([$this->line($this->steer('EX-A7'), ['weight_kg' => 400, 'price_per_kg' => 2000])])->assertStatus(201);

        $other = Company::create(['name' => 'Otra Empresa', 'cuit' => '30-71888888-8']);
        $this->linkUserToCompany($this->user, $other->id);

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string) $other->id)
            ->getJson($this->url('/exit-orders/animals'))
            ->assertOk()
            ->assertJsonCount(0);
    }
}
