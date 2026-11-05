<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\Batch;
use App\Models\Caravan;
use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A new tenant starts with a clean rodeo: the figures on its screens come from its animals, and the
 * test scenarios stay out of it until someone asks for them.
 */
class TenantSeedTest extends TestCase
{
    private Tenant $tenant;
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        $this->tenant = Tenant::create(['id' => 'test-tenant-' . uniqid()]);
        tenancy()->initialize($this->tenant);

        $this->companyId = (int) Company::query()->orderBy('id')->value('id');
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();
        Artisan::call('migrate:rollback');

        parent::tearDown();
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function ownBatches()
    {
        return DB::table('batches')
            ->where('company_id', $this->companyId)
            ->whereNull('farm_id')
            ->where('is_system', false)
            ->get();
    }

    public function test_the_company_starts_with_ten_active_batches_all_with_animals(): void
    {
        $batches = $this->ownBatches();

        $this->assertCount(10, $batches);
        $this->assertSame(10, $batches->where('is_active', true)->count());

        foreach ($batches as $batch) {
            $this->assertGreaterThan(0, DB::table('caravans')->where('batch_id', $batch->id)->count(), "{$batch->name} está vacío");
        }

        $this->assertSame(0, DB::table('caravans')->where('company_id', $this->companyId)->whereNull('batch_id')->count());
    }

    public function test_two_batches_are_under_a_natural_service_with_fit_bulls_among_their_cows(): void
    {
        $orders = DB::table('service_orders')->where('company_id', $this->companyId)->get();

        $this->assertCount(2, $orders);

        foreach ($orders as $order) {
            $this->assertSame('APPROVED', $order->status);
            $this->assertSame('NATURAL', $order->service_method);

            $cows = DB::table('caravans')->where('batch_id', $order->batch_id)->where('sex', 'H')->count();
            $this->assertSame($cows, DB::table('service_order_females')->where('service_order_id', $order->id)->count());

            $bulls = DB::table('service_order_males')->where('service_order_id', $order->id)->pluck('male_caravan_id');
            $this->assertCount(2, $bulls);
            $this->assertSame(2, DB::table('caravans')->where('batch_id', $order->batch_id)->whereIn('id', $bulls)->count());
            foreach (Caravan::withoutGlobalScopes()->whereIn('id', $bulls)->with('bullHealthEvaluation')->get() as $bull) {
                $this->assertSame('APT', $bull->bullHealthEvaluation?->status);
            }
        }
    }

    public function test_every_animal_has_its_category_and_a_current_weight(): void
    {
        $animals = DB::table('caravans')->where('company_id', $this->companyId);

        $this->assertGreaterThan(200, (clone $animals)->count());
        $this->assertSame(0, (clone $animals)->whereNull('category_id')->count());
        $this->assertSame(0, (clone $animals)->whereNull('breed_id')->count());
        $this->assertSame(
            (clone $animals)->count(),
            (clone $animals)->join('caravan_weights', 'caravan_weights.caravan_id', '=', 'caravans.id')->where('caravan_weights.current', true)->count(),
            'Cada animal tiene exactamente un peso vigente'
        );
    }

    public function test_the_figures_of_each_batch_come_from_its_animals(): void
    {
        foreach ($this->ownBatches() as $batch) {
            $weights = DB::table('caravans')
                ->join('caravan_weights', 'caravan_weights.caravan_id', '=', 'caravans.id')
                ->where('caravans.batch_id', $batch->id)
                ->where('caravan_weights.current', true)
                // The weights describe the herd: a bull of a running service is stock, but passing through.
                ->whereNotExists(fn ($stay) => $stay->selectRaw('1')
                    ->from('service_order_males')
                    ->join('service_orders', 'service_orders.id', '=', 'service_order_males.service_order_id')
                    ->whereColumn('service_order_males.male_caravan_id', 'caravans.id')
                    ->whereColumn('service_orders.batch_id', 'caravans.batch_id')
                    ->where('service_order_males.status', 'ACTIVE')
                    ->where('service_orders.status', 'APPROVED'));

            $this->assertSame(DB::table('caravans')->where('batch_id', $batch->id)->count(), (int) $batch->caravans_count, $batch->name);
            $this->assertSame((clone $weights)->count(), (int) $batch->weighed_count, $batch->name);
            $this->assertEqualsWithDelta((float) (clone $weights)->sum('caravan_weights.weight'), (float) $batch->total_weight, 0.01, $batch->name);
            $this->assertGreaterThanOrEqual(4, DB::table('batch_weights')->where('batch_id', $batch->id)->count(), "{$batch->name} sin curva");
        }
    }

    public function test_the_calves_at_foot_are_tied_to_their_mothers(): void
    {
        $batchId = DB::table('batches')->where('name', 'Vacas con Cría al Pie')->value('id');

        $this->assertSame(14, (int) Batch::withNursingCalves()->find($batchId)->nursing_count);
        $this->assertSame(14, DB::table('caravan_lineage')->where('is_nursing', true)->whereNotNull('mother_id')->count());
    }

    public function test_the_test_scenarios_can_still_be_seeded_on_top(): void
    {
        $this->seed(\Database\Seeders\TestScenariosSeeder::class);

        foreach (['LOTE TEST CRIA', 'Lote Testing Parición', 'Srv Rodeo en Servicio', 'Prueba Castración'] as $scenario) {
            $this->assertTrue(DB::table('batches')->where('name', $scenario)->exists(), "Falta {$scenario}");
        }
        $this->assertTrue(DB::table('caravans')->where('identification', 'DST-T-01')->exists());
        $this->assertTrue(DB::table('caravans')->where('identification', 'PAR-V-01')->exists());
    }
}
