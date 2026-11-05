<?php

declare(strict_types=1);

namespace Tests\Feature\Weighing;

use App\Core\Enums\PartnerRole;
use App\Core\Enums\WeighingTypeCode;
use App\Models\Activity;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\BatchWeight;
use App\Models\Caravan;
use App\Models\CaravanDeath;
use App\Models\CaravanWeight;
use App\Models\Company;
use App\Models\DeathCause;
use App\Models\ExitOrder;
use App\Models\ExitOrderAnimal;
use App\Models\Farm;
use App\Models\WeighingType;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Mcp\McpTestCase;
use Tests\Support\TestPartner;

/**
 * The first write of an AI client on the herd: one control weighing of one own caravan, with
 * blockers no confirmation can accept and warnings that wait for the user.
 */
class AiWeighingTest extends McpTestCase
{
    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->batch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote IA',
            'activity_id' => Activity::where('code', 'RECRIA')->firstOrFail()->id,
            'batch_type_id' => BatchType::where('code', 'GROWING_STEERS')->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    private function animal(string $tag, ?float $weight = 295.0, string $date = '2026-10-20', ?Batch $batch = null): Caravan
    {
        $caravan = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => ($batch ?? $this->batch)->id,
            'identification' => $tag,
            'sex' => 'M',
            'teeth' => 0,
        ]);

        if ($weight !== null) {
            $this->weighing($caravan, $weight, $date, true);
        }

        return $caravan;
    }

    private function weighing(Caravan $caravan, float $weight, string $date, bool $current): void
    {
        CaravanWeight::create([
            'weighing_type_id' => WeighingType::idOf(WeighingTypeCode::CONTROL),
            'caravan_id' => $caravan->id,
            'weight' => $weight,
            'weighing_date' => $date,
            'current' => $current,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function weigh(Caravan $caravan, float $weight, string $date, array $extra = []): TestResponse
    {
        return $this->callWith($this->sessionToken, 'POST', "/caravans/{$caravan->id}/ai-weighing", [
            'weight' => $weight,
            'weighing_date' => $date,
            ...$extra,
        ]);
    }

    private function weighings(Caravan $caravan): int
    {
        return CaravanWeight::where('caravan_id', $caravan->id)->count();
    }

    private function current(Caravan $caravan): CaravanWeight
    {
        return CaravanWeight::where('caravan_id', $caravan->id)->where('current', true)->firstOrFail();
    }

    public function test_records_a_control_weighing_marked_as_ai_and_recomputes_the_batch(): void
    {
        $caravan = $this->animal('IA-01');

        $this->weigh($caravan, 310, '2026-11-02')
            ->assertCreated()
            ->assertExactJson(['data' => [
                'caravan_id' => $caravan->id,
                'identification' => 'IA-01',
                'previous_weight' => 295,
                'previous_date' => '2026-10-20',
                'new_weight' => 310,
                'delta_kg' => 15,
                'daily_change_kg' => 1.15,
                'becomes_current' => true,
                'weighing_date' => '2026-11-02',
                'warnings' => [],
            ]]);

        $weight = $this->current($caravan);
        $this->assertEqualsWithDelta(310.0, (float) $weight->weight, 0.01);
        $this->assertSame('AI', $weight->source);
        $this->assertSame(WeighingType::idOf(WeighingTypeCode::CONTROL), $weight->weighing_type_id);
        $this->assertSame('INDIVIDUAL', $weight->method);

        $point = BatchWeight::where('batch_id', $this->batch->id)->latest('id')->firstOrFail();
        $this->assertSame('2026-11-02', $point->weighing_date->format('Y-m-d'));
        $this->assertEqualsWithDelta(310.0, (float) $point->weight, 0.01);
    }

    public function test_a_first_weighing_has_nothing_to_compare_with(): void
    {
        $this->weigh($this->animal('IA-02', null), 310, '2026-11-02')
            ->assertCreated()
            ->assertJsonPath('data.previous_weight', null)
            ->assertJsonPath('data.delta_kg', null)
            ->assertJsonPath('data.daily_change_kg', null)
            ->assertJsonPath('data.becomes_current', true);
    }

    public function test_type_and_source_of_the_body_are_ignored(): void
    {
        $caravan = $this->animal('IA-03');

        $this->weigh($caravan, 310, '2026-11-02', ['source' => 'MANUAL', 'weighing_type' => 'ENTRY'])->assertCreated();

        $weight = $this->current($caravan);
        $this->assertSame('AI', $weight->source);
        $this->assertSame(WeighingType::idOf(WeighingTypeCode::CONTROL), $weight->weighing_type_id);
    }

    public function test_a_caravan_of_another_company_does_not_exist_here(): void
    {
        $other = Company::create(['name' => 'Otra Estancia', 'cuit' => '30-71555555-5']);
        $foreign = Caravan::withoutGlobalScopes()->create(['company_id' => $other->id, 'identification' => 'AJENA-1', 'sex' => 'M', 'teeth' => 0]);

        $this->weigh($foreign, 310, '2026-11-02')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'CARAVAN_NOT_FOUND');

        $this->assertSame(0, $this->weighings($foreign));
    }

    public function test_a_caravan_of_an_external_batch_is_refused(): void
    {
        $provider = TestPartner::create(['company_id' => $this->company->id, 'name' => 'Proveedor IA', 'cuit' => '30-71666666-6', 'roles' => [PartnerRole::SUPPLIER_LIVESTOCK]]);
        $farm = Farm::create(['name' => 'Campo Ajeno', 'renspa' => '01.002.3.00789/00', 'provider_id' => $provider->id, 'is_active' => true]);
        $external = Batch::create(['company_id' => $this->company->id, 'name' => 'Lote Externo IA', 'farm_id' => $farm->id, 'is_active' => true]);
        $caravan = $this->animal('IA-EXT', null, batch: $external);

        $this->weigh($caravan, 310, '2026-11-02')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'EXTERNAL_CARAVAN');

        $this->assertSame(0, $this->weighings($caravan));
    }

    public function test_a_future_date_is_refused(): void
    {
        $caravan = $this->animal('IA-04');

        $this->weigh($caravan, 310, now()->addDays(2)->toDateString())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['weighing_date' => 'La fecha del pesaje no puede ser futura.']);

        $this->assertSame(1, $this->weighings($caravan));
    }

    public function test_a_dead_animal_is_not_weighed_after_its_death_even_if_warnings_were_accepted(): void
    {
        $caravan = $this->animal('IA-05');
        CaravanDeath::create([
            'company_id' => $this->company->id,
            'caravan_id' => $caravan->id,
            'death_date' => '2026-10-25',
            'death_cause_id' => DeathCause::where('company_id', $this->company->id)->firstOrFail()->id,
            'from_batch_id' => $this->batch->id,
            'death_batch_id' => $this->batch->id,
        ]);

        $this->weigh($caravan, 900, '2026-11-02', ['acknowledge_warnings' => true])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'ALREADY_DEAD');

        $this->assertSame(1, $this->weighings($caravan));
    }

    private function sold(Caravan $caravan, string $date): void
    {
        $order = ExitOrder::create([
            'company_id' => $this->company->id,
            'code' => 'EG-IA-' . $caravan->id,
            'kind' => 'REGISTERED',
            'reason' => 'SALE',
            'status' => 'EXECUTED',
            'exit_date' => $date,
            'planned_head_count' => 1,
        ]);

        ExitOrderAnimal::create([
            'company_id' => $this->company->id,
            'exit_order_id' => $order->id,
            'caravan_id' => $caravan->id,
            'status' => 'EXITED',
            'exited_at' => $date,
        ]);
    }

    public function test_a_sold_animal_is_not_weighed_after_it_left(): void
    {
        $caravan = $this->animal('IA-06');
        $this->sold($caravan, '2026-10-28');

        $this->weigh($caravan, 310, '2026-11-02')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'WEIGHED_AFTER_EXIT')
            ->assertJsonPath('message', 'IA-06 salió el 28/10/2026: no se puede pesar después de esa fecha.');

        $this->assertSame(1, $this->weighings($caravan));
    }

    public function test_a_sold_animal_can_be_weighed_up_to_the_day_it_left(): void
    {
        $caravan = $this->animal('IA-07');
        $this->sold($caravan, '2026-10-28');

        $this->weigh($caravan, 310, '2026-10-28')->assertCreated();

        $this->assertSame(2, $this->weighings($caravan));
    }

    public function test_repeating_the_same_weighing_adds_nothing(): void
    {
        $caravan = $this->animal('IA-08');

        $this->weigh($caravan, 310, '2026-11-02')->assertCreated();
        $this->weigh($caravan, 310, '2026-11-02')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'DUPLICATE_WEIGHING')
            ->assertJsonPath('message', 'IA-08 ya tiene un pesaje de 310 kg del 02/11/2026; no se registró otro.');
        $this->weigh($caravan, 310, '2026-11-02', ['acknowledge_warnings' => true])->assertUnprocessable();

        $this->assertSame(2, $this->weighings($caravan));
    }

    public function test_a_warning_waits_for_the_user_and_is_written_once_accepted(): void
    {
        $caravan = $this->animal('IA-09');

        $this->weigh($caravan, 810, '2026-11-02')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'WEIGHING_WARNINGS')
            ->assertJsonPath('errors.domain.0', 'No se registró el pesaje. Mostrale estas advertencias al usuario y reintentá sólo si confirma: Pesó 295 kg el 20/10/2026 y ahora 810 kg: +39,6 kg/día. Revisá la lectura.');
        $this->assertSame(1, $this->weighings($caravan));

        $this->weigh($caravan, 810, '2026-11-02', ['acknowledge_warnings' => true])
            ->assertCreated()
            ->assertJsonCount(1, 'data.warnings');
        $this->assertSame(2, $this->weighings($caravan));
    }

    public function test_a_weight_impossible_for_a_bovine_waits_for_the_user(): void
    {
        $this->weigh($this->animal('IA-10', null), 1500, '2026-11-02')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'WEIGHING_WARNINGS');
    }

    public function test_another_weight_of_the_same_day_corrects_a_mistake_once_accepted(): void
    {
        $caravan = $this->animal('IA-11');
        $this->weigh($caravan, 310, '2026-11-02')->assertCreated();

        $this->weigh($caravan, 301, '2026-11-02')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'WEIGHING_WARNINGS');

        $this->weigh($caravan, 301, '2026-11-02', ['acknowledge_warnings' => true])
            ->assertCreated()
            // Compared with the last weighing before that day, not with the mistake.
            ->assertJsonPath('data.previous_weight', 295)
            ->assertJsonPath('data.becomes_current', true);

        $this->assertEqualsWithDelta(301.0, (float) $this->current($caravan)->weight, 0.01);
        $this->assertSame(3, $this->weighings($caravan));
    }

    public function test_a_weighing_dated_before_the_last_one_does_not_change_the_current_weight(): void
    {
        $caravan = $this->animal('IA-12');
        $this->weighing($caravan, 280, '2026-10-01', false);

        $this->weigh($caravan, 290, '2026-10-10')
            ->assertCreated()
            ->assertJsonPath('data.previous_weight', 280)
            ->assertJsonPath('data.previous_date', '2026-10-01')
            ->assertJsonPath('data.becomes_current', false);

        $this->assertEqualsWithDelta(295.0, (float) $this->current($caravan)->weight, 0.01);
    }

    public function test_an_invalid_request_is_refused(): void
    {
        $caravan = $this->animal('IA-13');

        $this->weigh($caravan, 0, '2026-11-02')->assertJsonValidationErrors('weight');
        $this->weigh($caravan, 2001, '2026-11-02')->assertJsonValidationErrors('weight');
        $this->weigh($caravan, 310, '02/11/2026')->assertJsonValidationErrors('weighing_date');
        $this->weigh($caravan, 310, '2026-11-02', ['notes' => str_repeat('x', 501)])->assertJsonValidationErrors('notes');

        $this->assertSame(1, $this->weighings($caravan));
    }

    public function test_requires_a_token(): void
    {
        $caravan = $this->animal('IA-14');

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeaders(['X-Company-ID' => (string) $this->company->id])
            ->postJson($this->url("/caravans/{$caravan->id}/ai-weighing"), ['weight' => 310, 'weighing_date' => '2026-11-02'])
            ->assertUnauthorized();

        $this->assertSame(1, $this->weighings($caravan));
    }
}
