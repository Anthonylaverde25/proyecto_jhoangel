<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\BatchWeight;
use App\Models\Breed;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;
use App\Models\Color;
use App\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Simulates progressive entries of steer cohorts over 5 months into batch "lote ejemplo peso 1" (ID: 20).
 *
 * Reproduces real biological herd dynamics:
 *  - Cohort 1 (May 2026): 8 steers (~175.8 kg avg)
 *  - Cohort 2 (June 2026): 6 steers (~185.8 kg avg) after Cohort 1 gained weight (+0.80 kg/day)
 *  - Cohort 3 (July 2026): 7 steers (~196.2 kg avg) after previous cohorts gained weight (+0.76 kg/day)
 *  - Cohort 4 (August 2026): 5 steers (~209.0 kg avg) after previous cohorts gained weight (+0.78 kg/day)
 *  - Present (September 2026): Control weighing (+0.75 kg/day) + integration of pre-existing caravan DST-T-20
 *
 * Final state: 27 steers, ~6,749.3 kg mass, ~250.0 kg average.
 */
class SimulateBatchProgressiveEntriesSeeder extends Seeder
{
    private const BATCH_NAME = 'lote ejemplo peso 1';

    public function run(): void
    {
        $company = Company::first();
        if (!$company) {
            $this->command?->warn('SimulateBatchProgressiveEntriesSeeder: No company found. Skipping.');
            return;
        }

        $companyId = (int) $company->id;

        // Resolve Batch 20
        $batch = Batch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where(function ($query) {
                $query->where('id', 20)
                    ->orWhere('name', self::BATCH_NAME);
            })
            ->first();

        if (!$batch) {
            $this->command?->error('SimulateBatchProgressiveEntriesSeeder: Target batch not found.');
            return;
        }

        $recriaActivityId = Activity::withoutGlobalScopes()->where('code', 'RECRIA')->value('id') ?? $batch->activity_id;
        $novillitoCategoryId = AnimalCategory::withoutGlobalScopes()->where('code', 'NOVILLITO')->value('id');

        // Available breeds and colors
        $breeds = Breed::withoutGlobalScopes()->pluck('id', 'name')->toArray();
        $colors = Color::withoutGlobalScopes()->pluck('id', 'name')->toArray();

        $breedIds = array_values($breeds) ?: [null];
        $colorIds = array_values($colors) ?: [null];

        // Clean previous simulated caravans for idempotency (tags starting with LEP1-)
        $existingSimulated = Caravan::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('identification', 'like', 'LEP1-%')
            ->get();

        foreach ($existingSimulated as $oldCaravan) {
            CaravanWeight::where('caravan_id', $oldCaravan->id)->delete();
            CaravanMovement::where('caravan_id', $oldCaravan->id)->delete();
            $oldCaravan->delete();
        }

        // Clean existing BatchWeight records for this batch to build a clean chronological history
        BatchWeight::where('batch_id', $batch->id)->delete();

        // ---------------------------------------------------------------------
        // Data Definitions for the 4 Cohorts
        // ---------------------------------------------------------------------
        $cohort1Data = [
            ['tag' => 'LEP1-001', 'w0' => 165.0],
            ['tag' => 'LEP1-002', 'w0' => 168.5],
            ['tag' => 'LEP1-003', 'w0' => 172.0],
            ['tag' => 'LEP1-004', 'w0' => 174.5],
            ['tag' => 'LEP1-005', 'w0' => 178.0],
            ['tag' => 'LEP1-006', 'w0' => 180.5],
            ['tag' => 'LEP1-007', 'w0' => 182.0],
            ['tag' => 'LEP1-008', 'w0' => 186.0],
        ];

        $cohort2Data = [
            ['tag' => 'LEP1-009', 'w0' => 180.0],
            ['tag' => 'LEP1-010', 'w0' => 182.5],
            ['tag' => 'LEP1-011', 'w0' => 184.0],
            ['tag' => 'LEP1-012', 'w0' => 186.5],
            ['tag' => 'LEP1-013', 'w0' => 189.5],
            ['tag' => 'LEP1-014', 'w0' => 192.0],
        ];

        $cohort3Data = [
            ['tag' => 'LEP1-015', 'w0' => 188.0],
            ['tag' => 'LEP1-016', 'w0' => 190.5],
            ['tag' => 'LEP1-017', 'w0' => 194.0],
            ['tag' => 'LEP1-018', 'w0' => 196.5],
            ['tag' => 'LEP1-019', 'w0' => 198.5],
            ['tag' => 'LEP1-020', 'w0' => 202.0],
            ['tag' => 'LEP1-021', 'w0' => 204.0],
        ];

        $cohort4Data = [
            ['tag' => 'LEP1-022', 'w0' => 202.0],
            ['tag' => 'LEP1-023', 'w0' => 205.5],
            ['tag' => 'LEP1-024', 'w0' => 209.0],
            ['tag' => 'LEP1-025', 'w0' => 212.5],
            ['tag' => 'LEP1-026', 'w0' => 216.0],
        ];

        // ---------------------------------------------------------------------
        // Milestone 1: 2026-05-15 (Opening & Cohort 1 Intake)
        // ---------------------------------------------------------------------
        $dateM1 = Carbon::parse('2026-05-15');

        // Initial batch opening record
        BatchWeight::create([
            'batch_id' => $batch->id,
            'activity_id' => $recriaActivityId,
            'weight' => null,
            'total_weight' => 0.0,
            'caravans_count' => 0,
            'weighed_count' => 0,
            'weights_as_of' => null,
            'type' => 'INITIAL',
            'weighing_date' => $dateM1->toDateString(),
            'created_at' => $dateM1,
            'updated_at' => $dateM1,
        ]);

        $cohort1Caravans = [];
        $cohort1WeightsM1 = [];

        foreach ($cohort1Data as $idx => $item) {
            $caravan = Caravan::create([
                'company_id' => $companyId,
                'batch_id' => $batch->id,
                'identification' => $item['tag'],
                'sex' => 'M',
                'teeth' => 0,
                'category_id' => $novillitoCategoryId,
                'breed_id' => $breedIds[$idx % count($breedIds)],
                'color_id' => $colorIds[$idx % count($colorIds)],
                'entry_date' => $dateM1->toDateString(),
                'entry_weight' => $item['w0'],
                'renspa' => 'NO_DEFINIDO',
                'created_at' => $dateM1,
                'updated_at' => $dateM1,
            ]);

            CaravanMovement::create([
                'caravan_id' => $caravan->id,
                'company_id' => $companyId,
                'to_batch_id' => $batch->id,
                'from_batch_id' => null,
                'type' => 'ENTRY',
                'movement_date' => $dateM1,
                'observations' => 'Ingreso Lote 1 - Tropa Compra Terneros Invernada',
                'renspa' => 'NO_DEFINIDO',
                'created_at' => $dateM1,
                'updated_at' => $dateM1,
            ]);

            CaravanWeight::create([
                'caravan_id' => $caravan->id,
                'weight' => $item['w0'],
                'current' => false,
                'weighing_date' => $dateM1->toDateString(),
                'notes' => 'Pesaje de ingreso tropa Mayo 2026',
                'created_at' => $dateM1,
                'updated_at' => $dateM1,
            ]);

            $cohort1Caravans[] = $caravan;
            $cohort1WeightsM1[] = $item['w0'];
        }

        $m1Total = array_sum($cohort1WeightsM1);
        $m1Avg = round($m1Total / count($cohort1WeightsM1), 2);

        BatchWeight::create([
            'batch_id' => $batch->id,
            'activity_id' => $recriaActivityId,
            'weight' => $m1Avg,
            'total_weight' => $m1Total,
            'caravans_count' => count($cohort1Caravans),
            'weighed_count' => count($cohort1Caravans),
            'weights_as_of' => $dateM1->toDateString(),
            'type' => 'MOVEMENT_IN',
            'weighing_date' => $dateM1->toDateString(),
            'created_at' => $dateM1,
            'updated_at' => $dateM1,
        ]);

        // ---------------------------------------------------------------------
        // Milestone 2: 2026-06-18 (Control Cohort 1 + Cohort 2 Intake)
        // ---------------------------------------------------------------------
        $dateM2 = Carbon::parse('2026-06-18');
        // Cohort 1 gains ~27.2 kg (ADG ~0.80 kg/d over 34 days)
        $gainM2 = 27.2;
        $cohort1WeightsM2 = [];

        foreach ($cohort1Caravans as $idx => $caravan) {
            $newWeight = round($cohort1Data[$idx]['w0'] + $gainM2, 1);
            $cohort1WeightsM2[] = $newWeight;

            CaravanWeight::create([
                'caravan_id' => $caravan->id,
                'weight' => $newWeight,
                'current' => false,
                'weighing_date' => $dateM2->toDateString(),
                'notes' => 'Control pesada mensual Junio',
                'created_at' => $dateM2,
                'updated_at' => $dateM2,
            ]);
        }

        $m2ControlTotal = array_sum($cohort1WeightsM2);
        $m2ControlAvg = round($m2ControlTotal / count($cohort1WeightsM2), 2);

        BatchWeight::create([
            'batch_id' => $batch->id,
            'activity_id' => $recriaActivityId,
            'weight' => $m2ControlAvg,
            'total_weight' => $m2ControlTotal,
            'caravans_count' => count($cohort1Caravans),
            'weighed_count' => count($cohort1Caravans),
            'weights_as_of' => $dateM2->toDateString(),
            'type' => 'CONTROL',
            'weighing_date' => $dateM2->toDateString(),
            'created_at' => $dateM2,
            'updated_at' => $dateM2,
        ]);

        // Cohort 2 arrives
        $cohort2Caravans = [];
        $cohort2WeightsM2 = [];

        foreach ($cohort2Data as $idx => $item) {
            $caravan = Caravan::create([
                'company_id' => $companyId,
                'batch_id' => $batch->id,
                'identification' => $item['tag'],
                'sex' => 'M',
                'teeth' => 0,
                'category_id' => $novillitoCategoryId,
                'breed_id' => $breedIds[($idx + 2) % count($breedIds)],
                'color_id' => $colorIds[($idx + 1) % count($colorIds)],
                'entry_date' => $dateM2->toDateString(),
                'entry_weight' => $item['w0'],
                'renspa' => 'NO_DEFINIDO',
                'created_at' => $dateM2,
                'updated_at' => $dateM2,
            ]);

            CaravanMovement::create([
                'caravan_id' => $caravan->id,
                'company_id' => $companyId,
                'to_batch_id' => $batch->id,
                'from_batch_id' => null,
                'type' => 'ENTRY',
                'movement_date' => $dateM2,
                'observations' => 'Ingreso Lote 2 - Destete propio campo Los Álamos',
                'renspa' => 'NO_DEFINIDO',
                'created_at' => $dateM2,
                'updated_at' => $dateM2,
            ]);

            CaravanWeight::create([
                'caravan_id' => $caravan->id,
                'weight' => $item['w0'],
                'current' => false,
                'weighing_date' => $dateM2->toDateString(),
                'notes' => 'Pesaje de ingreso tropa Junio 2026',
                'created_at' => $dateM2,
                'updated_at' => $dateM2,
            ]);

            $cohort2Caravans[] = $caravan;
            $cohort2WeightsM2[] = $item['w0'];
        }

        $m2AllWeights = array_merge($cohort1WeightsM2, $cohort2WeightsM2);
        $m2AllCaravans = array_merge($cohort1Caravans, $cohort2Caravans);
        $m2MovementTotal = array_sum($m2AllWeights);
        $m2MovementAvg = round($m2MovementTotal / count($m2AllWeights), 2);

        BatchWeight::create([
            'batch_id' => $batch->id,
            'activity_id' => $recriaActivityId,
            'weight' => $m2MovementAvg,
            'total_weight' => $m2MovementTotal,
            'caravans_count' => count($m2AllCaravans),
            'weighed_count' => count($m2AllCaravans),
            'weights_as_of' => $dateM2->toDateString(),
            'type' => 'MOVEMENT_IN',
            'weighing_date' => $dateM2->toDateString(),
            'created_at' => $dateM2,
            'updated_at' => $dateM2,
        ]);

        // ---------------------------------------------------------------------
        // Milestone 3: 2026-07-22 (Control 14 head + Cohort 3 Intake)
        // ---------------------------------------------------------------------
        $dateM3 = Carbon::parse('2026-07-22');
        // Gain ~25.8 kg (ADG ~0.76 kg/d over 34 days)
        $gainM3 = 25.8;
        $cohort1WeightsM3 = [];
        $cohort2WeightsM3 = [];

        foreach ($cohort1Caravans as $idx => $caravan) {
            $newWeight = round($cohort1WeightsM2[$idx] + $gainM3, 1);
            $cohort1WeightsM3[] = $newWeight;

            CaravanWeight::create([
                'caravan_id' => $caravan->id,
                'weight' => $newWeight,
                'current' => false,
                'weighing_date' => $dateM3->toDateString(),
                'notes' => 'Control pesada mensual Julio',
                'created_at' => $dateM3,
                'updated_at' => $dateM3,
            ]);
        }

        foreach ($cohort2Caravans as $idx => $caravan) {
            $newWeight = round($cohort2WeightsM2[$idx] + $gainM3, 1);
            $cohort2WeightsM3[] = $newWeight;

            CaravanWeight::create([
                'caravan_id' => $caravan->id,
                'weight' => $newWeight,
                'current' => false,
                'weighing_date' => $dateM3->toDateString(),
                'notes' => 'Control pesada mensual Julio',
                'created_at' => $dateM3,
                'updated_at' => $dateM3,
            ]);
        }

        $m3ControlWeights = array_merge($cohort1WeightsM3, $cohort2WeightsM3);
        $m3ControlTotal = array_sum($m3ControlWeights);
        $m3ControlAvg = round($m3ControlTotal / count($m3ControlWeights), 2);

        BatchWeight::create([
            'batch_id' => $batch->id,
            'activity_id' => $recriaActivityId,
            'weight' => $m3ControlAvg,
            'total_weight' => $m3ControlTotal,
            'caravans_count' => count($m3ControlWeights),
            'weighed_count' => count($m3ControlWeights),
            'weights_as_of' => $dateM3->toDateString(),
            'type' => 'CONTROL',
            'weighing_date' => $dateM3->toDateString(),
            'created_at' => $dateM3,
            'updated_at' => $dateM3,
        ]);

        // Cohort 3 arrives
        $cohort3Caravans = [];
        $cohort3WeightsM3 = [];

        foreach ($cohort3Data as $idx => $item) {
            $caravan = Caravan::create([
                'company_id' => $companyId,
                'batch_id' => $batch->id,
                'identification' => $item['tag'],
                'sex' => 'M',
                'teeth' => 0,
                'category_id' => $novillitoCategoryId,
                'breed_id' => $breedIds[($idx + 4) % count($breedIds)],
                'color_id' => $colorIds[($idx + 2) % count($colorIds)],
                'entry_date' => $dateM3->toDateString(),
                'entry_weight' => $item['w0'],
                'renspa' => 'NO_DEFINIDO',
                'created_at' => $dateM3,
                'updated_at' => $dateM3,
            ]);

            CaravanMovement::create([
                'caravan_id' => $caravan->id,
                'company_id' => $companyId,
                'to_batch_id' => $batch->id,
                'from_batch_id' => null,
                'type' => 'ENTRY',
                'movement_date' => $dateM3,
                'observations' => 'Ingreso Lote 3 - Traslado recría terneros corral norte',
                'renspa' => 'NO_DEFINIDO',
                'created_at' => $dateM3,
                'updated_at' => $dateM3,
            ]);

            CaravanWeight::create([
                'caravan_id' => $caravan->id,
                'weight' => $item['w0'],
                'current' => false,
                'weighing_date' => $dateM3->toDateString(),
                'notes' => 'Pesaje de ingreso tropa Julio 2026',
                'created_at' => $dateM3,
                'updated_at' => $dateM3,
            ]);

            $cohort3Caravans[] = $caravan;
            $cohort3WeightsM3[] = $item['w0'];
        }

        $m3AllWeights = array_merge($m3ControlWeights, $cohort3WeightsM3);
        $m3AllCaravans = array_merge($m2AllCaravans, $cohort3Caravans);
        $m3MovementTotal = array_sum($m3AllWeights);
        $m3MovementAvg = round($m3MovementTotal / count($m3AllWeights), 2);

        BatchWeight::create([
            'batch_id' => $batch->id,
            'activity_id' => $recriaActivityId,
            'weight' => $m3MovementAvg,
            'total_weight' => $m3MovementTotal,
            'caravans_count' => count($m3AllCaravans),
            'weighed_count' => count($m3AllCaravans),
            'weights_as_of' => $dateM3->toDateString(),
            'type' => 'MOVEMENT_IN',
            'weighing_date' => $dateM3->toDateString(),
            'created_at' => $dateM3,
            'updated_at' => $dateM3,
        ]);

        // ---------------------------------------------------------------------
        // Milestone 4: 2026-08-23 (Control 21 head + Cohort 4 Intake)
        // ---------------------------------------------------------------------
        $dateM4 = Carbon::parse('2026-08-23');
        // Gain ~25.0 kg (ADG ~0.78 kg/d over 32 days)
        $gainM4 = 25.0;
        $cohort1WeightsM4 = [];
        $cohort2WeightsM4 = [];
        $cohort3WeightsM4 = [];

        foreach ($cohort1Caravans as $idx => $caravan) {
            $newWeight = round($cohort1WeightsM3[$idx] + $gainM4, 1);
            $cohort1WeightsM4[] = $newWeight;

            CaravanWeight::create([
                'caravan_id' => $caravan->id,
                'weight' => $newWeight,
                'current' => false,
                'weighing_date' => $dateM4->toDateString(),
                'notes' => 'Control pesada mensual Agosto',
                'created_at' => $dateM4,
                'updated_at' => $dateM4,
            ]);
        }

        foreach ($cohort2Caravans as $idx => $caravan) {
            $newWeight = round($cohort2WeightsM3[$idx] + $gainM4, 1);
            $cohort2WeightsM4[] = $newWeight;

            CaravanWeight::create([
                'caravan_id' => $caravan->id,
                'weight' => $newWeight,
                'current' => false,
                'weighing_date' => $dateM4->toDateString(),
                'notes' => 'Control pesada mensual Agosto',
                'created_at' => $dateM4,
                'updated_at' => $dateM4,
            ]);
        }

        foreach ($cohort3Caravans as $idx => $caravan) {
            $newWeight = round($cohort3WeightsM3[$idx] + $gainM4, 1);
            $cohort3WeightsM4[] = $newWeight;

            CaravanWeight::create([
                'caravan_id' => $caravan->id,
                'weight' => $newWeight,
                'current' => false,
                'weighing_date' => $dateM4->toDateString(),
                'notes' => 'Control pesada mensual Agosto',
                'created_at' => $dateM4,
                'updated_at' => $dateM4,
            ]);
        }

        $m4ControlWeights = array_merge($cohort1WeightsM4, $cohort2WeightsM4, $cohort3WeightsM4);
        $m4ControlTotal = array_sum($m4ControlWeights);
        $m4ControlAvg = round($m4ControlTotal / count($m4ControlWeights), 2);

        BatchWeight::create([
            'batch_id' => $batch->id,
            'activity_id' => $recriaActivityId,
            'weight' => $m4ControlAvg,
            'total_weight' => $m4ControlTotal,
            'caravans_count' => count($m4ControlWeights),
            'weighed_count' => count($m4ControlWeights),
            'weights_as_of' => $dateM4->toDateString(),
            'type' => 'CONTROL',
            'weighing_date' => $dateM4->toDateString(),
            'created_at' => $dateM4,
            'updated_at' => $dateM4,
        ]);

        // Cohort 4 arrives
        $cohort4Caravans = [];
        $cohort4WeightsM4 = [];

        foreach ($cohort4Data as $idx => $item) {
            $caravan = Caravan::create([
                'company_id' => $companyId,
                'batch_id' => $batch->id,
                'identification' => $item['tag'],
                'sex' => 'M',
                'teeth' => 0,
                'category_id' => $novillitoCategoryId,
                'breed_id' => $breedIds[($idx + 1) % count($breedIds)],
                'color_id' => $colorIds[($idx + 3) % count($colorIds)],
                'entry_date' => $dateM4->toDateString(),
                'entry_weight' => $item['w0'],
                'renspa' => 'NO_DEFINIDO',
                'created_at' => $dateM4,
                'updated_at' => $dateM4,
            ]);

            CaravanMovement::create([
                'caravan_id' => $caravan->id,
                'company_id' => $companyId,
                'to_batch_id' => $batch->id,
                'from_batch_id' => null,
                'type' => 'ENTRY',
                'movement_date' => $dateM4,
                'observations' => 'Ingreso Lote 4 - Selección lote corral 3',
                'renspa' => 'NO_DEFINIDO',
                'created_at' => $dateM4,
                'updated_at' => $dateM4,
            ]);

            CaravanWeight::create([
                'caravan_id' => $caravan->id,
                'weight' => $item['w0'],
                'current' => false,
                'weighing_date' => $dateM4->toDateString(),
                'notes' => 'Pesaje de ingreso tropa Agosto 2026',
                'created_at' => $dateM4,
                'updated_at' => $dateM4,
            ]);

            $cohort4Caravans[] = $caravan;
            $cohort4WeightsM4[] = $item['w0'];
        }

        $m4AllWeights = array_merge($m4ControlWeights, $cohort4WeightsM4);
        $m4AllCaravans = array_merge($m3AllCaravans, $cohort4Caravans);
        $m4MovementTotal = array_sum($m4AllWeights);
        $m4MovementAvg = round($m4MovementTotal / count($m4AllWeights), 2);

        BatchWeight::create([
            'batch_id' => $batch->id,
            'activity_id' => $recriaActivityId,
            'weight' => $m4MovementAvg,
            'total_weight' => $m4MovementTotal,
            'caravans_count' => count($m4AllCaravans),
            'weighed_count' => count($m4AllCaravans),
            'weights_as_of' => $dateM4->toDateString(),
            'type' => 'MOVEMENT_IN',
            'weighing_date' => $dateM4->toDateString(),
            'created_at' => $dateM4,
            'updated_at' => $dateM4,
        ]);

        // ---------------------------------------------------------------------
        // Milestone 5: 2026-09-18 (Control 26 head + Caravan 161 Integration)
        // ---------------------------------------------------------------------
        $dateM5 = Carbon::parse('2026-09-18');
        // Gain ~19.5 kg (ADG ~0.75 kg/d over 26 days)
        $gainM5 = 19.5;
        $cohort1WeightsM5 = [];
        $cohort2WeightsM5 = [];
        $cohort3WeightsM5 = [];
        $cohort4WeightsM5 = [];

        // All 26 simulated caravans get their current=true final weights
        foreach ($cohort1Caravans as $idx => $caravan) {
            $newWeight = round($cohort1WeightsM4[$idx] + $gainM5, 1);
            $cohort1WeightsM5[] = $newWeight;

            CaravanWeight::create([
                'caravan_id' => $caravan->id,
                'weight' => $newWeight,
                'current' => true,
                'weighing_date' => $dateM5->toDateString(),
                'notes' => 'Control pesada general Septiembre',
                'created_at' => $dateM5,
                'updated_at' => $dateM5,
            ]);
        }

        foreach ($cohort2Caravans as $idx => $caravan) {
            $newWeight = round($cohort2WeightsM4[$idx] + $gainM5, 1);
            $cohort2WeightsM5[] = $newWeight;

            CaravanWeight::create([
                'caravan_id' => $caravan->id,
                'weight' => $newWeight,
                'current' => true,
                'weighing_date' => $dateM5->toDateString(),
                'notes' => 'Control pesada general Septiembre',
                'created_at' => $dateM5,
                'updated_at' => $dateM5,
            ]);
        }

        foreach ($cohort3Caravans as $idx => $caravan) {
            $newWeight = round($cohort3WeightsM4[$idx] + $gainM5, 1);
            $cohort3WeightsM5[] = $newWeight;

            CaravanWeight::create([
                'caravan_id' => $caravan->id,
                'weight' => $newWeight,
                'current' => true,
                'weighing_date' => $dateM5->toDateString(),
                'notes' => 'Control pesada general Septiembre',
                'created_at' => $dateM5,
                'updated_at' => $dateM5,
            ]);
        }

        foreach ($cohort4Caravans as $idx => $caravan) {
            $newWeight = round($cohort4WeightsM4[$idx] + $gainM5, 1);
            $cohort4WeightsM5[] = $newWeight;

            CaravanWeight::create([
                'caravan_id' => $caravan->id,
                'weight' => $newWeight,
                'current' => true,
                'weighing_date' => $dateM5->toDateString(),
                'notes' => 'Control pesada general Septiembre',
                'created_at' => $dateM5,
                'updated_at' => $dateM5,
            ]);
        }

        $m5ControlWeights = array_merge($cohort1WeightsM5, $cohort2WeightsM5, $cohort3WeightsM5, $cohort4WeightsM5);
        $m5ControlTotal = array_sum($m5ControlWeights);
        $m5ControlAvg = round($m5ControlTotal / count($m5ControlWeights), 2);

        BatchWeight::create([
            'batch_id' => $batch->id,
            'activity_id' => $recriaActivityId,
            'weight' => $m5ControlAvg,
            'total_weight' => $m5ControlTotal,
            'caravans_count' => count($m5ControlWeights),
            'weighed_count' => count($m5ControlWeights),
            'weights_as_of' => $dateM5->toDateString(),
            'type' => 'CONTROL',
            'weighing_date' => $dateM5->toDateString(),
            'created_at' => $dateM5,
            'updated_at' => $dateM5,
        ]);

        // Pre-existing caravan in batch 20 (DST-T-20, weight: 199.00 kg)
        $preExistingCaravan = Caravan::withoutGlobalScopes()
            ->where('batch_id', $batch->id)
            ->where('identification', 'not like', 'LEP1-%')
            ->first();

        $allFinalWeights = $m5ControlWeights;
        $totalHeadCount = count($m5ControlWeights);

        if ($preExistingCaravan) {
            $existingWeight = $preExistingCaravan->weights()->where('current', true)->value('weight')
                ?? $preExistingCaravan->weights()->latest('weighing_date')->value('weight')
                ?? 199.0;

            $allFinalWeights[] = (float) $existingWeight;
            $totalHeadCount += 1;
        }

        $finalTotalMass = array_sum($allFinalWeights);
        $finalAvgWeight = round($finalTotalMass / $totalHeadCount, 2);

        BatchWeight::create([
            'batch_id' => $batch->id,
            'activity_id' => $recriaActivityId,
            'weight' => $finalAvgWeight,
            'total_weight' => $finalTotalMass,
            'caravans_count' => $totalHeadCount,
            'weighed_count' => $totalHeadCount,
            'weights_as_of' => $dateM5->toDateString(),
            'type' => 'MOVEMENT_IN',
            'weighing_date' => $dateM5->toDateString(),
            'created_at' => $dateM5,
            'updated_at' => $dateM5,
        ]);

        // ---------------------------------------------------------------------
        // Sync Batch Aggregate Fields
        // ---------------------------------------------------------------------
        $minWeight = min($allFinalWeights);
        $maxWeight = max($allFinalWeights);

        $batch->update([
            'caravans_count' => $totalHeadCount,
            'weighed_count' => $totalHeadCount,
            'current_weight' => $finalAvgWeight,
            'total_weight' => $finalTotalMass,
            'min_weight' => $minWeight,
            'max_weight' => $maxWeight,
        ]);

        $this->command?->info(sprintf(
            'SimulateBatchProgressiveEntriesSeeder successfully executed: Batch "%s" now holds %d caravans, total weight %.1f kg, avg %.1f kg (min: %.1f kg, max: %.1f kg).',
            $batch->name,
            $totalHeadCount,
            $finalTotalMass,
            $finalAvgWeight,
            $minWeight,
            $maxWeight
        ));
    }
}
