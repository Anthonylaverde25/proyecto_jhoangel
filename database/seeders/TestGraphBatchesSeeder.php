<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\BatchWeight;
use App\Models\Caravan;
use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * Seeds two empty batches intended for user testing and graph behavior experimentation:
 *  1. 'LOTE DE INGRESO ANIMALES TEST'
 *  2. 'LOTE DESTINO EGRESO ANIMALES TEST'
 *
 * Both batches are initialized with 0 animals, 0 mass, null average, and an INITIAL
 * point in batch_weights so that the graph opens cleanly without any fabricated zero weights.
 */
class TestGraphBatchesSeeder extends Seeder
{
    public const INGRESS_BATCH_NAME = 'LOTE DE INGRESO ANIMALES TEST';
    public const EGRESS_BATCH_NAME = 'LOTE DESTINO EGRESO ANIMALES TEST';

    public function run(): void
    {
        $company = Company::first();

        if (!$company) {
            $this->command?->warn('TestGraphBatchesSeeder: No company found. Skipping.');
            return;
        }

        $companyId = (int) $company->id;

        $criaActivityId = Activity::withoutGlobalScopes()->where('code', 'CRIA')->value('id')
            ?? Activity::withoutGlobalScopes()->first()?->id;

        $recriaActivityId = Activity::withoutGlobalScopes()->where('code', 'RECRIA')->value('id')
            ?? Activity::withoutGlobalScopes()->first()?->id;

        $weaningTypeId = BatchType::withoutGlobalScopes()->where('code', 'WEANING')->value('id')
            ?? BatchType::withoutGlobalScopes()->first()?->id;

        $steersTypeId = BatchType::withoutGlobalScopes()->where('code', 'GROWING_STEERS')->value('id')
            ?? BatchType::withoutGlobalScopes()->first()?->id;

        // ---------------------------------------------------------------------
        // 1. Lote de Ingreso Animales Test (CRIA / WEANING)
        // ---------------------------------------------------------------------
        $ingressBatch = Batch::withoutGlobalScopes()->updateOrCreate(
            [
                'company_id' => $companyId,
                'name' => self::INGRESS_BATCH_NAME,
            ],
            [
                'activity_id' => $criaActivityId,
                'batch_type_id' => $weaningTypeId,
                'is_active' => true,
                'is_confined' => false,
                'current_weight' => null,
                'total_weight' => 0.0,
                'caravans_count' => 0,
                'weighed_count' => 0,
                'min_weight' => null,
                'max_weight' => null,
            ]
        );

        // Ensure 0 animals
        Caravan::withoutGlobalScopes()
            ->where('batch_id', $ingressBatch->id)
            ->update(['batch_id' => null]);

        // Reset batch weights to clean initial point
        BatchWeight::where('batch_id', $ingressBatch->id)->delete();
        BatchWeight::create([
            'batch_id' => $ingressBatch->id,
            'activity_id' => $criaActivityId,
            'weight' => null,
            'total_weight' => 0.0,
            'caravans_count' => 0,
            'weighed_count' => 0,
            'type' => 'INITIAL',
            'weighing_date' => now()->toDateString(),
        ]);

        // ---------------------------------------------------------------------
        // 2. Lote Destino Egreso Animales Test (RECRIA / GROWING_STEERS)
        // ---------------------------------------------------------------------
        $egressBatch = Batch::withoutGlobalScopes()->updateOrCreate(
            [
                'company_id' => $companyId,
                'name' => self::EGRESS_BATCH_NAME,
            ],
            [
                'activity_id' => $recriaActivityId,
                'batch_type_id' => $steersTypeId,
                'is_active' => true,
                'is_confined' => false,
                'current_weight' => null,
                'total_weight' => 0.0,
                'caravans_count' => 0,
                'weighed_count' => 0,
                'min_weight' => null,
                'max_weight' => null,
            ]
        );

        // Ensure 0 animals
        Caravan::withoutGlobalScopes()
            ->where('batch_id', $egressBatch->id)
            ->update(['batch_id' => null]);

        // Reset batch weights to clean initial point
        BatchWeight::where('batch_id', $egressBatch->id)->delete();
        BatchWeight::create([
            'batch_id' => $egressBatch->id,
            'activity_id' => $recriaActivityId,
            'weight' => null,
            'total_weight' => 0.0,
            'caravans_count' => 0,
            'weighed_count' => 0,
            'type' => 'INITIAL',
            'weighing_date' => now()->toDateString(),
        ]);

        $this->command?->info(sprintf(
            'TestGraphBatchesSeeder executed: "%s" (ID: %d) and "%s" (ID: %d) created with 0 animals ready for graph testing.',
            $ingressBatch->name,
            $ingressBatch->id,
            $egressBatch->name,
            $egressBatch->id
        ));
    }
}
