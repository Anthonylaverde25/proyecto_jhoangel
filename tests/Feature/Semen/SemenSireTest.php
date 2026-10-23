<?php

declare(strict_types=1);

namespace Tests\Feature\Semen;

use App\Core\Enums\AnimalSex;
use App\Models\Breed;
use App\Models\Caravan;
use Illuminate\Support\Str;

/** Padres de semen: externo o toro propio (SS1–SS5, DA6). */
class SemenSireTest extends SemenTestCase
{
    public function test_external_sire_and_repeated_registry(): void
    {
        $breed = Breed::query()->firstOrCreate(['name' => 'Angus test']);
        $this->apiAs('POST', '/semen/sires', ['name' => '  Don   Cirilo ', 'registry_code' => ' hba 77 ', 'breed_id' => $breed->id, 'stud_name' => 'La Esperanza'])
            ->assertStatus(201)
            ->assertJsonPath('name', 'Don Cirilo')
            ->assertJsonPath('registry_code', 'HBA 77')
            ->assertJsonPath('breed.name', 'Angus test')
            ->assertJsonPath('is_own_bull', false);

        // SS5: the same registry is the same bull.
        $this->apiAs('POST', '/semen/sires', ['name' => 'Otro', 'registry_code' => 'HBA 77'])->assertStatus(422)
            ->assertJsonPath('errors.registry_code.0', 'Ese registro ya es de Don Cirilo (HBA 77): elegilo en lugar de cargarlo de nuevo.');
        $this->apiAs('POST', '/semen/sires', ['registry_code' => 'X1'])->assertStatus(422)->assertJsonValidationErrors('name');

        // Other company: nothing shared.
        $this->asOther(fn () => $this->apiAs('POST', '/semen/sires', ['name' => 'Don Cirilo', 'registry_code' => 'HBA 77'])->assertStatus(201));
    }

    public function test_own_bull_is_a_male_caravan_of_the_company(): void
    {
        $bull = $this->caravan(AnimalSex::MALE, 'T-' . random_int(100, 999));
        $cow = $this->caravan(AnimalSex::FEMALE, 'V-' . random_int(100, 999));

        $this->apiAs('POST', '/semen/sires', ['caravan_id' => $cow->id])->assertStatus(422)
            ->assertJsonPath('errors.caravan_id.0', 'Elegí un macho de esta empresa.');
        $this->asOther(fn () => $this->apiAs('POST', '/semen/sires', ['caravan_id' => $bull->id])->assertStatus(422)->assertJsonValidationErrors('caravan_id'));

        // SS2: name from the caravan, whatever was typed.
        $id = $this->apiAs('POST', '/semen/sires', ['caravan_id' => $bull->id, 'name' => 'Ignorado'])->assertStatus(201)
            ->assertJsonPath('name', $bull->identification)
            ->assertJsonPath('is_own_bull', true)
            ->assertJsonPath('caravan.identification', $bull->identification)
            ->json('id');
        $this->apiAs('POST', '/semen/sires', ['caravan_id' => $bull->id])->assertStatus(422)->assertJsonValidationErrors('caravan_id');
        $this->apiAs('PATCH', "/semen/sires/{$id}", ['name' => 'Cambiado', 'stud_name' => 'Propia'])->assertOk()
            ->assertJsonPath('name', $bull->identification)->assertJsonPath('stud_name', 'Propia');
    }

    public function test_a_sire_with_active_semen_stays_active(): void
    {
        $sire = $this->sire('Rambo activo');
        $this->receipt([$this->receiptLine($sire, 'L-1', 5)])->assertStatus(201);

        $this->apiAs('PATCH', "/semen/sires/{$sire}/active", ['is_active' => false])->assertStatus(422)->assertJsonValidationErrors('domain');
        $free = $this->sire('Sin semen');
        $this->apiAs('PATCH', "/semen/sires/{$free}/active", ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $this->assertNotContains($free, collect($this->apiAs('GET', '/semen/sires')->json())->pluck('id')->all());
        $this->assertContains($free, collect($this->apiAs('GET', '/semen/sires?include_inactive=1')->json())->pluck('id')->all());
    }

    private function caravan(AnimalSex $sex, string $tag): Caravan
    {
        return Caravan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'renspa' => 'NO_DEFINIDO', 'identification' => $tag . Str::upper(Str::random(3)),
            'teeth' => 0, 'sex' => $sex,
        ]);
    }
}
