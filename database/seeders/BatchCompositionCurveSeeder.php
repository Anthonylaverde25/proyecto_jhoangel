<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Enums\BatchWeightCause;
use App\Core\Services\BatchWeightService;
use App\Models\Activity;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * Reproduces the worked examples of the weight-curve plan so that the behaviour can be
 * looked at on screen: a batch whose average drops because the heaviest animals were
 * classified out of it, a destination that already held animals, and a batch emptied
 * altogether.
 *
 * The point to verify: every drop is a transfer of cattle, and the series says so.
 */
class BatchCompositionCurveSeeder extends Seeder
{
    private const PER_HALF = 20;

    public function run(): void
    {
        $company = Company::first();

        if ($company === null) {
            return;
        }

        $service = app(BatchWeightService::class);

        $origin = $this->makeBatch($company->id, 'CURVA · Destete 2026', 'CRIA', 'WEANING');

        // Two genuine weighings three weeks apart: this is the biological stretch.
        $light = [];
        $heavy = [];

        for ($i = 1; $i <= self::PER_HALF; $i++) {
            $light[] = $this->makeCaravan($company->id, $origin, "CUR-L-{$i}", 'H');
            $heavy[] = $this->makeCaravan($company->id, $origin, "CUR-P-{$i}", 'M');
        }

        $this->weighAll($light, 150.0, now()->subDays(28));
        $this->weighAll($heavy, 200.0, now()->subDays(28));
        $this->recordSeries($service, $origin, BatchWeightCause::CONTROL, now()->subDays(28));

        $this->weighAll($light, 155.0, now()->subDays(7));
        $this->weighAll($heavy, 205.0, now()->subDays(7));
        $this->recordSeries($service, $origin, BatchWeightCause::CONTROL, now()->subDays(7));

        // --- Example 3.1: the heaviest half is classified out to a brand new batch ---
        $steers = $this->makeBatch($company->id, 'CURVA · Novillitos 2026', 'RECRIA', 'GROWING_STEERS');

        $service->snapshotBeforeMovement($origin->id);
        $this->move($heavy, $origin, $steers, $company->id);
        $service->recalculateBatchWeight($origin->id, BatchWeightCause::MOVEMENT_OUT);
        $service->recalculateBatchWeight($steers->id, BatchWeightCause::MOVEMENT_IN);

        // --- Example 3.3: a destination that already held animals ---
        $heifers = $this->makeBatch($company->id, 'CURVA · Vaquillonas de Recría', 'RECRIA', 'GROWING_HEIFERS');

        $resident = [];
        for ($i = 1; $i <= 10; $i++) {
            $resident[] = $this->makeCaravan($company->id, $heifers, "CUR-V-{$i}", 'H');
        }
        $this->weighAll($resident, 225.0, now()->subDays(10));
        $this->recordSeries($service, $heifers, BatchWeightCause::CONTROL, now()->subDays(10));

        $service->snapshotBeforeMovement($origin->id);
        $service->snapshotBeforeMovement($heifers->id);
        $this->move($light, $origin, $heifers, $company->id);
        $service->recalculateBatchWeight($origin->id, BatchWeightCause::MOVEMENT_OUT);
        $service->recalculateBatchWeight($heifers->id, BatchWeightCause::MOVEMENT_IN);

        // --- Example 3.5: the origin is now empty: zero kilos, undefined average ---
        // (it was emptied by the transfer above; nothing else to do)

        // Lotes vacíos para pruebas de ingreso y egreso del usuario
        $this->call(TestGraphBatchesSeeder::class);
    }

    private function makeBatch(int $companyId, string $name, string $activityCode, string $typeCode): Batch
    {
        return Batch::firstOrCreate(
            ['company_id' => $companyId, 'name' => $name],
            [
                'activity_id' => Activity::where('code', $activityCode)->value('id'),
                'batch_type_id' => BatchType::where('code', $typeCode)->value('id'),
                'is_active' => true,
                'is_confined' => false,
            ]
        );
    }

    private function makeCaravan(int $companyId, Batch $batch, string $identification, string $sex): Caravan
    {
        return Caravan::firstOrCreate(
            ['company_id' => $companyId, 'identification' => $identification],
            ['batch_id' => $batch->id, 'sex' => $sex, 'teeth' => 0]
        );
    }

    /** @param Caravan[] $caravans */
    private function weighAll(array $caravans, float $weight, \DateTimeInterface $date): void
    {
        foreach ($caravans as $caravan) {
            $caravan->weights()->update(['current' => false]);
            $caravan->weights()->create([
                'weight' => $weight,
                'weighing_date' => $date->format('Y-m-d'),
                'current' => true,
            ]);
        }
    }

    /**
     * Records a series point and backdates it, so that the curve spans real time instead
     * of collapsing onto the day the seeder ran.
     */
    private function recordSeries(
        BatchWeightService $service,
        Batch $batch,
        BatchWeightCause $cause,
        \DateTimeInterface $date
    ): void {
        $service->recalculateBatchWeight($batch->id, $cause);

        \App\Models\BatchWeight::where('batch_id', $batch->id)
            ->orderByDesc('id')
            ->limit(1)
            ->update(['weighing_date' => $date->format('Y-m-d')]);
    }

    /** @param Caravan[] $caravans */
    private function move(array $caravans, Batch $from, Batch $to, int $companyId): void
    {
        foreach ($caravans as $caravan) {
            $caravan->refresh();
            $originId = $caravan->batch_id;
            $caravan->update(['batch_id' => $to->id]);

            CaravanMovement::create([
                'caravan_id' => $caravan->id,
                'company_id' => $companyId,
                'renspa' => 'NO_DEFINIDO',
                'type' => 'TRANSFER',
                'movement_date' => now(),
                'from_batch_id' => $originId,
                'to_batch_id' => $to->id,
                'observations' => "Clasificación desde '{$from->name}'",
            ]);
        }
    }
}
