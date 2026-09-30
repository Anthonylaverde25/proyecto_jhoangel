<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\AnimalSubcategory;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\BatchWeight;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;
use App\Models\Company;
use App\Models\TransferOrder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * El escenario para probar las órdenes de transferencia contra la zootecnia del rodeo: cada
 * etapa con los animales que de verdad la habitan, y un lote destino vacío por etapa, para que
 * cada transición real (destete, recría, terminación, entore, refugo) y cada transición que no
 * debería ser posible (un novillo que vuelve a ternero, un novillito que vuelve a ser entero) se
 * pueda intentar sobre animales conocidos.
 *
 * Las caravanas llevan el prefijo ZOO- y los lotes "Zoo …", para que se reconozcan a simple vista.
 *
 *  - "Zoo Cría Destete" (Cría, destete): ZOO-TM-01..06 terneros y ZOO-TH-01..06 terneras, DL.
 *  - "Zoo Recría Novillitos" (Recría, novillitos): ZOO-NV-01..04 Novillito, 2D, ~330 kg.
 *  - "Zoo Recría Vaquillonas" (Recría, vientres de reposición): ZOO-VQ-01..04 Vaquillona /
 *    Reposición, 4D, ~320 kg.
 *  - "Zoo Cría Rodeo" (Cría, servicio): ZOO-VC-01..04 Vaca / Rodeo General, 8D, ~450 kg
 *    (ZOO-VC-01 preñada de cinco meses) y ZOO-TR-01 Toro Reproductor, ~780 kg.
 *  - Destinos vacíos: "Zoo Recría Machos" (novillitos), "Zoo Recría Hembras" (vientres de
 *    reposición), "Zoo Invernada Terminación" (Invernada, corral), "Zoo Cría Servicio" (Cría,
 *    servicio).
 *
 * Idempotente: cada corrida devuelve cada animal a su lote, categoría, dentición y peso, y borra
 * las órdenes, los movimientos y la curva de estos lotes.
 *     php artisan tenants:seed --class=TransferOrderZootechnicsTestSeeder
 */
class TransferOrderZootechnicsTestSeeder extends Seeder
{
    private const DATE = '2026-09-01';

    public function run(): void
    {
        $company = Company::first();

        if (!$company) {
            $this->command?->warn('TransferOrderZootechnicsTestSeeder: no hay compañía cargada. Se omite.');

            return;
        }

        $companyId = (int) $company->id;

        $batches = [
            'destete' => $this->batch($companyId, 'Zoo Cría Destete', 'CRIA', 'WEANING', false),
            'novillitos' => $this->batch($companyId, 'Zoo Recría Novillitos', 'RECRIA', 'GROWING_STEERS', false),
            'vaquillonas' => $this->batch($companyId, 'Zoo Recría Vaquillonas', 'RECRIA', 'GROWING_REPLACEMENT_FEMALES', false),
            'rodeo' => $this->batch($companyId, 'Zoo Cría Rodeo', 'CRIA', 'SERVICE', false),
            'machos' => $this->batch($companyId, 'Zoo Recría Machos', 'RECRIA', 'GROWING_STEERS', false),
            'hembras' => $this->batch($companyId, 'Zoo Recría Hembras', 'RECRIA', 'GROWING_REPLACEMENT_FEMALES', false),
            'invernada' => $this->batch($companyId, 'Zoo Invernada Terminación', 'INVERNADA', 'OPERATIONAL', true),
            'servicio' => $this->batch($companyId, 'Zoo Cría Servicio', 'CRIA', 'SERVICE', false),
        ];
        $batchIds = array_map(fn (Batch $b) => (int) $b->id, $batches);

        // Orders first: their rolls point at the caravans and the batches.
        TransferOrder::withoutGlobalScopes()->where('company_id', $companyId)
            ->where(fn ($q) => $q->whereIn('source_batch_id', $batchIds))
            ->get()
            ->each(function (TransferOrder $order) {
                $order->animals()->withoutGlobalScopes()->delete();
                $order->destinations()->withoutGlobalScopes()->delete();
                $order->history()->withoutGlobalScopes()->delete();
                $order->delete();
            });

        $category = fn (string $code) => (int) AnimalCategory::withoutGlobalScopes()->where('code', $code)->value('id');
        $sub = fn (string $cat, string $code) => (int) AnimalSubcategory::withoutGlobalScopes()
            ->where('category_id', $category($cat))->where('code', $code)->value('id');

        $ids = [];
        foreach (range(1, 6) as $n) {
            $ids[] = $this->animal($companyId, sprintf('ZOO-TM-%02d', $n), $batches['destete'], 'M', 0, 180 + 8 * $n, $category('TERNERO'), null);
            $ids[] = $this->animal($companyId, sprintf('ZOO-TH-%02d', $n), $batches['destete'], 'H', 0, 170 + 8 * $n, $category('TERNERO'), null);
        }
        foreach (range(1, 4) as $n) {
            $ids[] = $this->animal($companyId, sprintf('ZOO-NV-%02d', $n), $batches['novillitos'], 'M', 2, 320 + 5 * $n, $category('NOVILLITO'), null);
            $ids[] = $this->animal($companyId, sprintf('ZOO-VQ-%02d', $n), $batches['vaquillonas'], 'H', 4, 310 + 5 * $n, $category('VAQUILLONA'), $sub('VAQUILLONA', 'REPOSICION'));
            $ids[] = $this->animal($companyId, sprintf('ZOO-VC-%02d', $n), $batches['rodeo'], 'H', 8, 440 + 5 * $n, $category('VACA'), $sub('VACA', 'RODEO_GENERAL'));
        }
        $ids[] = $this->animal($companyId, 'ZOO-TR-01', $batches['rodeo'], 'M', 8, 780, $category('TORO'), null);

        CaravanMovement::withoutGlobalScopes()->whereIn('caravan_id', $ids)->delete();
        BatchWeight::whereIn('batch_id', $batchIds)->delete();

        // ZOO-VC-01 preñada de cinco meses: lo que tiene que pesar al mandarla a terminación.
        $pregnant = (int) Caravan::withoutGlobalScopes()->where('company_id', $companyId)->where('identification', 'ZOO-VC-01')->value('id');
        DB::table('caravan_gestations')->whereIn('caravan_id', $ids)->delete();
        DB::table('caravan_gestations')->insert([
            'caravan_id' => $pregnant,
            'start_date' => '2026-04-01',
            'estimated_due_date' => '2027-01-10',
            'is_current' => true,
            'gestation_stage' => 'head',
            'gestation_months' => 5.0,
            'notes' => 'Preñez de prueba (TransferOrderZootechnicsTestSeeder).',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->refresh($batchIds);

        $this->command?->info('TransferOrderZootechnicsTestSeeder: escenario "Zoo" listo (' . count($ids) . ' animales, 8 lotes).');
    }

    private function batch(int $companyId, string $name, string $activity, string $type, bool $confined): Batch
    {
        return Batch::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'name' => $name],
            [
                'activity_id' => Activity::withoutGlobalScopes()->where('code', $activity)->value('id'),
                'batch_type_id' => BatchType::withoutGlobalScopes()->where('code', $type)->value('id'),
                'is_confined' => $confined,
                'is_active' => true,
                'is_system' => false,
                'observaciones' => 'Escenario de prueba de órdenes de transferencia (zootecnia).',
            ]
        );
    }

    private function animal(int $companyId, string $tag, Batch $batch, string $sex, int $teeth, float $weight, int $categoryId, ?int $subcategoryId): int
    {
        $caravan = Caravan::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'identification' => $tag],
            [
                'batch_id' => $batch->id,
                'sex' => $sex,
                'teeth' => $teeth,
                'category_id' => $categoryId,
                'subcategory_id' => $subcategoryId,
            ]
        );

        CaravanWeight::where('caravan_id', $caravan->id)->delete();
        CaravanWeight::create([
            'caravan_id' => $caravan->id,
            'weight' => $weight,
            'current' => true,
            'weighing_date' => self::DATE,
            'notes' => 'Peso base del escenario Zoo.',
        ]);

        return (int) $caravan->id;
    }

    /**
     * @param list<int> $batchIds
     */
    private function refresh(array $batchIds): void
    {
        foreach ($batchIds as $batchId) {
            $stats = DB::table('caravans')
                ->leftJoin('caravan_weights', fn ($j) => $j->on('caravan_weights.caravan_id', '=', 'caravans.id')->where('caravan_weights.current', true))
                ->where('caravans.batch_id', $batchId)
                ->selectRaw('COUNT(caravans.id) heads, COUNT(caravan_weights.id) weighed, SUM(caravan_weights.weight) total, MIN(caravan_weights.weight) min_w, MAX(caravan_weights.weight) max_w')
                ->first();

            Batch::withoutGlobalScopes()->whereKey($batchId)->update([
                'caravans_count' => (int) $stats->heads,
                'weighed_count' => (int) $stats->weighed,
                'total_weight' => (float) ($stats->total ?? 0),
                'current_weight' => $stats->weighed > 0 ? round((float) $stats->total / (int) $stats->weighed, 2) : null,
                'min_weight' => $stats->min_w,
                'max_weight' => $stats->max_w,
            ]);
        }
    }
}
