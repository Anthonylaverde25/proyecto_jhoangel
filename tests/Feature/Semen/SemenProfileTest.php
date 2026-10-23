<?php

declare(strict_types=1);

namespace Tests\Feature\Semen;

/** Ficha de la pajuela, completada después del ingreso (P12). */
class SemenProfileTest extends SemenTestCase
{
    public function test_straw_profile_is_completed_later(): void
    {
        $rambo = $this->sire('Rambo ficha');
        $item = $this->receipt([$this->receiptLine($rambo, 'L-1', 3)])->assertStatus(201)->json('lines.0.item.id');

        $this->apiAs('PATCH', "/items/{$item}/semen-profile", ['preservation' => 'FROZEN', 'straw_size' => 'MINI_0_25', 'straw_color' => 'BLUE', 'cap_color' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('cap_color');
        $this->apiAs('PATCH', "/items/{$item}/semen-profile", ['preservation' => 'FROZEN', 'straw_size' => 'MINI_0_25', 'straw_color' => 'BLUE'])
            ->assertOk()->assertJsonPath('straw_size.label', 'Mini 0,25 ml')->assertJsonPath('straw_color.label', 'Azul');
        $this->apiAs('GET', "/items/{$item}")->assertOk()->assertJsonPath('semen_profile.straw_color.value', 'BLUE');

        [$other] = $this->stockedItem();
        $this->apiAs('PATCH', "/items/{$other}/semen-profile", ['preservation' => 'FROZEN'])->assertNotFound();
    }
}
