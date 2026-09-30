<?php

declare(strict_types=1);

namespace Tests\Feature\BirthOrders;

use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * Two breeding batches with pregnant females: the smallest herd a birth order has to handle, since
 * one order may list females of several batches and a calf is born in its mother's batch.
 */
abstract class BirthOrderTestCase extends VeterinaryTestCase
{
    protected Batch $breedingA;
    protected Batch $breedingB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->breedingA = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Rodeo Cría A BO',
            'activity_id' => $this->activityId('CRIA'),
            'is_confined' => false,
            'is_active' => true,
        ]);

        $this->breedingB = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Rodeo Cría B BO',
            'activity_id' => $this->activityId('CRIA'),
            'is_confined' => false,
            'is_active' => true,
        ]);
    }

    /**
     * A pregnant female, due in `$dueInDays` days, with the given candidate sires.
     *
     * @param Caravan[] $sires
     */
    protected function pregnantFemale(string $tag, ?Batch $batch = null, int $dueInDays = 5, array $sires = [], string $category = 'VAQUILLONA'): Caravan
    {
        $batch ??= $this->breedingA;

        $female = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $batch->id,
            'identification' => $tag,
            'sex' => 'H',
            'category_id' => $this->categoryId($category),
        ]);

        $gestation = CaravanGestation::create([
            'caravan_id' => $female->id,
            'start_date' => now()->addDays($dueInDays)->subDays(283)->toDateString(),
            'estimated_due_date' => now()->addDays($dueInDays)->toDateString(),
            'is_current' => true,
            'gestation_stage' => 'head',
            'gestation_months' => 3.0,
        ]);

        foreach ($sires as $sire) {
            DB::table('gestation_sires')->insert([
                'gestation_id' => $gestation->id,
                'sire_id' => $sire->id,
                'is_confirmed' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $female;
    }

    protected function bull(string $tag): Caravan
    {
        return Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->breedingA->id,
            'identification' => $tag,
            'sex' => 'M',
            'category_id' => $this->categoryId('TORO'),
        ]);
    }

    /**
     * @param Caravan[] $females
     * @param array<string, mixed> $overrides
     */
    protected function emit(array $females, array $overrides = [])
    {
        return $this->apiAs('POST', '/birth-orders', [
            'issue' => true,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addDays(60)->toDateString(),
            'responsable' => 'Recorredor',
            'animals' => array_map(fn (Caravan $c) => ['caravan_id' => $c->id], $females),
            ...$overrides,
        ]);
    }

    /**
     * A scanned PAR-01 sheet.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $overrides
     */
    protected function scan(?int $orderId, array $rows, array $overrides = [])
    {
        return $this->apiAs('POST', '/work-templates/par-01/process', [
            'birth_order_id' => $orderId,
            'fecha_recorrida' => now()->format('d/m/Y'),
            'rows' => $rows,
            ...$overrides,
        ]);
    }

    /**
     * A live-calving row for the sheet.
     *
     * @return array<string, mixed>
     */
    protected function liveRow(Caravan $mother, string $calfTag, string $sex = 'M', ?float $weight = 32.0, ?string $date = null): array
    {
        return [
            'caravana_madre' => $mother->identification,
            'resultado' => 'V',
            'caravana_cria' => $calfTag,
            'sexo' => $sex,
            'peso' => $weight,
            'fecha_nacimiento' => $date ?? now()->subDay()->format('d/m/Y'),
        ];
    }

    protected function categoryId(string $code): int
    {
        return (int) AnimalCategory::where('code', $code)->value('id');
    }

    protected function activityId(string $code): int
    {
        return (int) Activity::withoutGlobalScopes()->where('code', $code)->value('id');
    }

    protected function currentGestation(Caravan $female): ?CaravanGestation
    {
        return CaravanGestation::where('caravan_id', $female->id)->where('is_current', true)->first();
    }
}
