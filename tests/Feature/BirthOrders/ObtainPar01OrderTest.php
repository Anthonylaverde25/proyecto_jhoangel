<?php

declare(strict_types=1);

namespace Tests\Feature\BirthOrders;

use App\Models\BirthOrder;
use App\Models\BirthOrderAnimal;
use App\Models\Caravan;

/**
 * "Obtener orden de parición": a sheet printed blank gets its order from the review before anything
 * is registered, so the same paper can be loaded again on later days against it.
 */
final class ObtainPar01OrderTest extends BirthOrderTestCase
{
    public function test_a_blank_sheet_obtains_an_order_with_every_female_on_it(): void
    {
        $calved = $this->pregnantFemale('OB-V1');
        $late = $this->pregnantFemale('OB-N1', dueInDays: -10);
        $waiting = $this->pregnantFemale('OB-P1', $this->breedingB);

        $response = $this->obtain([
            $this->liveRow($calved, 'OB-C1'),
            $this->mark($late, 'N'),
            ['caravana_madre' => $waiting->identification],
        ])->assertStatus(201);

        $this->assertSame('ISSUED', $response->json('data.status'));
        $this->assertSame('REGISTERED', $response->json('data.kind'));
        $this->assertSame(3, BirthOrderAnimal::where('birth_order_id', $response->json('data.id'))->where('status', 'PENDING')->count());

        // Nothing is registered: obtaining the order only writes the order.
        $this->assertFalse(Caravan::where('identification', 'OB-C1')->exists());
        $this->assertNotNull($this->currentGestation($calved));
    }

    public function test_the_obtained_order_takes_the_sheet_and_stays_open_for_the_next_round(): void
    {
        $calved = $this->pregnantFemale('OB-V2');
        $late = $this->pregnantFemale('OB-N2', dueInDays: -10);
        $waiting = $this->pregnantFemale('OB-P2');
        $rows = [
            $this->liveRow($calved, 'OB-C2'),
            $this->mark($late, 'N'),
            ['caravana_madre' => $waiting->identification],
        ];

        $order = $this->obtain($rows)->assertStatus(201);
        $orderId = (int) $order->json('data.id');

        $this->scan($orderId, $rows, ['orden_paricion' => $order->json('data.code')])->assertStatus(201);

        $this->assertTrue(Caravan::where('identification', 'OB-C2')->exists());
        $this->assertSame('OVERDUE', BirthOrderAnimal::where('birth_order_id', $orderId)->where('mother_caravan_id', $late->id)->value('status'));
        $this->assertSame('PENDING', BirthOrderAnimal::where('birth_order_id', $orderId)->where('mother_caravan_id', $waiting->id)->value('status'));
        $this->assertTrue($this->apiAs('GET', "/birth-orders/{$orderId}")->json('is_open'));
    }

    public function test_a_sheet_with_problems_obtains_nothing(): void
    {
        $calved = $this->pregnantFemale('OB-V3');
        $before = BirthOrder::count();

        $this->obtain([
            $this->liveRow($calved, 'OB-C3'),
            ['caravana_madre' => 'OB-NO-EXISTE', 'resultado' => 'V', 'caravana_cria' => 'OB-C3B', 'sexo' => 'M'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('row_errors.0.errors.0.code', 'MOTHER_NOT_FOUND');

        $this->assertSame($before, BirthOrder::count());
    }

    public function test_an_unmarked_female_without_pregnancy_cannot_stay_pending(): void
    {
        $open = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->breedingA->id,
            'identification' => 'OB-VACIA',
            'sex' => 'H',
            'category_id' => $this->categoryId('VAQUILLONA'),
        ]);

        $this->obtain([['caravana_madre' => $open->identification]])
            ->assertStatus(422)
            ->assertJsonPath('row_errors.0.errors.0.code', 'NO_ACTIVE_GESTATION');
    }

    public function test_a_female_held_by_another_order_blocks_it(): void
    {
        $held = $this->pregnantFemale('OB-H1');
        $this->emit([$held])->assertStatus(201);

        $this->obtain([['caravana_madre' => $held->identification]])
            ->assertStatus(422)
            ->assertJsonPath('row_errors.0.errors.0.code', 'ANIMAL_IN_OPEN_ORDER');
    }

    public function test_a_sheet_that_names_an_order_does_not_obtain_another(): void
    {
        $female = $this->pregnantFemale('OB-X1');

        $this->obtain([$this->liveRow($female, 'OB-CX1')], ['orden_paricion' => 'PA-20990101-0001'])->assertStatus(422);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $overrides
     */
    private function obtain(array $rows, array $overrides = [])
    {
        return $this->apiAs('POST', '/work-templates/par-01/order', [
            'fecha_recorrida' => now()->format('d/m/Y'),
            'rows' => $rows,
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mark(Caravan $mother, string $mark): array
    {
        return ['caravana_madre' => $mother->identification, 'resultado' => $mark, 'fecha_nacimiento' => now()->format('d/m/Y')];
    }
}
