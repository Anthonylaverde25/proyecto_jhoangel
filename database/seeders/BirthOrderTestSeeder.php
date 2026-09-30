<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\BatchWeight;
use App\Models\BirthOrder;
use App\Models\BirthOrderAnimal;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Models\CaravanLineage;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;
use App\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Pregnant females for the birth orders and the PAR-01 sheets.
 *
 *  - PAR-V-01..30: pregnant in "Lote Testing Parición", due from 10 days ago to 50 days ahead,
 *    in head, body and tail of the calving season. PAR-V-01..10 are heifers (Vaquillona), so a
 *    calving makes them cows; the rest are cows.
 *  - PAR-V-31..36: pregnant in "Lote Testing Parición (otro rodeo)": one order may list females
 *    of several batches, and each calf is born in its mother's.
 *  - Sires: PAR-V-01..12 have one candidate sire (PAR-TORO-01), so the system resolves it;
 *    PAR-V-13..24 have two (PAR-TORO-01 and 02), so a calf left without sire waits in
 *    "Sires pendientes"; the rest have none.
 *  - PAR-V-37: an open cow (no gestation) in the first batch, for an unplanned calving.
 *  - PAR-C-USADA: a calf tag already in use, for the duplicated-tag check.
 *  - PA-20260929-0001: an ISSUED order with PAR-V-01..20 and PAR-V-31..33.
 *
 * Re-running it puts every female back to pregnant and erases what the loads wrote: the calves
 * born to them, their weights, the losses, and every birth order of these females. No
 * migrate:fresh needed:
 *     php artisan tenants:seed --class=BirthOrderTestSeeder
 */
class BirthOrderTestSeeder extends Seeder
{
    private const BATCH_NAME = 'Lote Testing Parición';
    private const OTHER_BATCH_NAME = 'Lote Testing Parición (otro rodeo)';
    private const FEMALES = 36;
    private const OTHER_BATCH_FROM = 31;
    private const HEIFERS_UP_TO = 10;
    private const SINGLE_SIRE_UP_TO = 12;
    private const TWO_SIRES_UP_TO = 24;
    private const OPEN_COW = 'PAR-V-37';
    private const USED_CALF_TAG = 'PAR-C-USADA';

    /** Fixed, not generated: a test sheet prints it and must keep resolving. */
    public const ORDER_CODE = 'PA-20260929-0001';
    private const ORDER_DATE = '2026-09-29';
    private const ORDER_FEMALES = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 31, 32, 33];

    public function run(): void
    {
        $company = Company::first();

        if (!$company) {
            $this->command?->warn('BirthOrderTestSeeder: no hay compañía cargada. Se omite.');

            return;
        }

        $companyId = (int) $company->id;
        $categories = AnimalCategory::withoutGlobalScopes()->whereIn('code', ['VACA', 'VAQUILLONA', 'TORO', 'TERNERO'])->pluck('id', 'code');

        $batch = $this->resolveBatch($companyId, self::BATCH_NAME, 'Lote propio reservado a pruebas de PAR-01: vientres preñados.');
        $otherBatch = $this->resolveBatch($companyId, self::OTHER_BATCH_NAME, 'Otro rodeo de cría con vientres preñados: una orden puede listar vientres de varios lotes.');

        $bullA = $this->caravan($companyId, 'PAR-TORO-01', (int) $batch->id, 'M', $categories['TORO'] ?? null);
        $bullB = $this->caravan($companyId, 'PAR-TORO-02', (int) $batch->id, 'M', $categories['TORO'] ?? null);

        $femaleIds = [];
        for ($n = 1; $n <= self::FEMALES; $n++) {
            $femaleIds[$n] = (int) $this->caravan(
                $companyId,
                $this->tag($n),
                $n >= self::OTHER_BATCH_FROM ? (int) $otherBatch->id : (int) $batch->id,
                'H',
                $n <= self::HEIFERS_UP_TO ? ($categories['VAQUILLONA'] ?? null) : ($categories['VACA'] ?? null)
            )->id;
        }

        $openCow = $this->caravan($companyId, self::OPEN_COW, (int) $batch->id, 'H', $categories['VACA'] ?? null);
        $this->caravan($companyId, self::USED_CALF_TAG, (int) $batch->id, 'M', $categories['TERNERO'] ?? null);

        $this->eraseLoads([...array_values($femaleIds), (int) $openCow->id], [(int) $batch->id, (int) $otherBatch->id]);

        $gestationIds = [];
        foreach ($femaleIds as $n => $id) {
            $sires = $n <= self::SINGLE_SIRE_UP_TO ? [$bullA] : ($n <= self::TWO_SIRES_UP_TO ? [$bullA, $bullB] : []);
            $gestationIds[$n] = $this->pregnancy($id, $this->dueDate($n), $sires);
        }

        $this->seedOrder($companyId, $femaleIds, $gestationIds);

        $this->command?->info(sprintf(
            'BirthOrderTestSeeder: %d vientres preñados (PAR-V-01..%02d) en "%s" y "%s", %s vacía, %s usada y la orden %s emitida.',
            self::FEMALES,
            self::FEMALES,
            self::BATCH_NAME,
            self::OTHER_BATCH_NAME,
            self::OPEN_COW,
            self::USED_CALF_TAG,
            self::ORDER_CODE
        ));
    }

    private function tag(int $n): string
    {
        return 'PAR-V-' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Staggered from 10 days ago to 50 days ahead, so the season has a head, a body and a tail.
     */
    private function dueDate(int $n): string
    {
        return now()->subDays(10)->addDays((int) round(($n - 1) * 60 / (self::FEMALES - 1)))->toDateString();
    }

    private function resolveBatch(int $companyId, string $name, string $observaciones): Batch
    {
        $criaActivity = Activity::withoutGlobalScopes()->where('code', 'CRIA')->first()
            ?? Activity::create(['code' => 'CRIA', 'name' => 'Cría']);

        return Batch::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'name' => $name],
            [
                'farm_id' => null,
                'activity_id' => $criaActivity->id,
                'is_active' => true,
                'is_system' => false,
                'is_confined' => false,
                'observaciones' => $observaciones,
            ]
        );
    }

    private function caravan(int $companyId, string $tag, int $batchId, string $sex, ?int $categoryId): Caravan
    {
        return Caravan::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'identification' => $tag],
            ['batch_id' => $batchId, 'sex' => $sex, 'category_id' => $categoryId, 'teeth' => $sex === 'H' ? 4 : 6]
        );
    }

    /**
     * @param Caravan[] $sires
     */
    private function pregnancy(int $femaleId, string $dueDate, array $sires): int
    {
        $daysToDue = (int) round((strtotime($dueDate) - strtotime(now()->toDateString())) / 86400);
        $stage = $daysToDue <= 10 ? 'head' : ($daysToDue <= 30 ? 'body' : 'tail');

        $gestation = CaravanGestation::create([
            'caravan_id' => $femaleId,
            'start_date' => date('Y-m-d', (int) strtotime($dueDate . ' -283 days')),
            'estimated_due_date' => $dueDate,
            'is_current' => true,
            'gestation_stage' => $stage,
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

        return (int) $gestation->id;
    }

    /**
     * What previous loads left behind: every birth order of these females, the calves born to them
     * and their records, and every gestation — the current one is seeded again.
     *
     * @param list<int> $femaleIds
     * @param list<int> $batchIds
     */
    private function eraseLoads(array $femaleIds, array $batchIds): void
    {
        $orderIds = BirthOrderAnimal::withoutGlobalScopes()->whereIn('mother_caravan_id', $femaleIds)->pluck('birth_order_id')->unique();
        BirthOrder::withoutGlobalScopes()->whereIn('id', $orderIds)->delete();

        $calfIds = CaravanLineage::whereIn('mother_id', $femaleIds)->pluck('caravan_id')->all();
        CaravanWeight::whereIn('caravan_id', $calfIds)->delete();
        CaravanMovement::withoutGlobalScopes()->whereIn('caravan_id', $calfIds)->delete();
        CaravanLineage::whereIn('caravan_id', $calfIds)->delete();
        Caravan::withoutGlobalScopes()->whereIn('id', $calfIds)->delete();

        $gestationIds = CaravanGestation::whereIn('caravan_id', $femaleIds)->pluck('id');
        DB::table('gestation_sires')->whereIn('gestation_id', $gestationIds)->delete();
        CaravanGestation::whereIn('id', $gestationIds)->delete();

        BatchWeight::whereIn('batch_id', $batchIds)->delete();
    }

    /**
     * @param array<int, int> $femaleIds
     * @param array<int, int> $gestationIds
     */
    private function seedOrder(int $companyId, array $femaleIds, array $gestationIds): void
    {
        $order = BirthOrder::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'code' => self::ORDER_CODE,
            'status' => 'ISSUED',
            'kind' => 'PLANNED',
            'period_start' => self::ORDER_DATE,
            'period_end' => now()->addDays(50)->toDateString(),
            'planned_head_count' => count(self::ORDER_FEMALES),
            'emitted_at' => self::ORDER_DATE . ' 08:00:00',
            'responsable' => 'Recorredor de prueba',
        ]);

        $batchIds = Caravan::withoutGlobalScopes()->whereIn('id', $femaleIds)->pluck('batch_id', 'id');

        foreach (self::ORDER_FEMALES as $n) {
            BirthOrderAnimal::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'birth_order_id' => $order->id,
                'mother_caravan_id' => $femaleIds[$n],
                'gestation_id' => $gestationIds[$n],
                'source_batch_id' => $batchIds[$femaleIds[$n]] ?? null,
                'status' => 'PENDING',
            ]);
        }

        DB::table('birth_order_histories')->insert([
            'company_id' => $companyId,
            'birth_order_id' => $order->id,
            'from_status' => null,
            'to_status' => 'ISSUED',
            'action_reason' => 'Orden de parición de prueba sembrada',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
