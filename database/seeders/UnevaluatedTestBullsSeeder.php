<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\BullHealthEvaluation;
use App\Models\BullLabSample;
use App\Models\Caravan;
use App\Models\Company;
use App\Models\Veterinarian;
use App\Models\VeterinarianBatchAssignment;
use App\Models\VeterinaryDiagnosis;
use Illuminate\Database\Seeder;

/**
 * Three bulls with a deliberately empty sanitary history, in an own (internal) batch.
 *
 * Every other seeded bull already carries biometry, protocols and determinations, which makes
 * them useless for exercising the rules from zero: how the aptitude engine behaves with no
 * sampling at all, and how the service order lock tells "never evaluated" apart from "unfit".
 *
 * Two properties make that work:
 *
 *  1. It runs LAST in TenantDatabaseSeeder, after BullAptitudeRecalculationSeeder — which
 *     creates an evaluation row for any bull lacking one, and would otherwise stop these
 *     three from being raw.
 *  2. It actively purges evaluations, determinations and findings for these caravans, so the
 *     starting state holds even if the chain is re-run or a recompute passed over them.
 */
class UnevaluatedTestBullsSeeder extends Seeder
{
    private const BATCH_NAME = 'Lote Testing Sanitario (crudo)';

    private const TAGS = [
        'ANTHONY-TORO-TEST-001',
        'ANTHONY-TORO-TEST-002',
        'ANTHONY-TORO-TEST-003',
    ];

    public function run(): void
    {
        $company = Company::first();

        if (!$company) {
            $this->command?->warn('UnevaluatedTestBullsSeeder: no hay compañía cargada. Se omite.');

            return;
        }

        $companyId = (int) $company->id;

        $batch = $this->resolveOwnBreedingBatch($companyId);
        $caravanIds = $this->resolveBulls($companyId, (int) $batch->id);
        $purged = $this->stripSanitaryHistory($companyId, $caravanIds);
        $this->assignToPortalVeterinarian($companyId, (int) $batch->id);

        $this->command?->info(sprintf(
            'UnevaluatedTestBullsSeeder: %d toros crudos en el lote propio "%s" (id %d).',
            count($caravanIds),
            $batch->name,
            $batch->id
        ));

        if ($purged > 0) {
            $this->command?->info("  Se purgaron {$purged} registros sanitarios previos para dejarlos en cero.");
        }
    }

    /**
     * Without an assignment the portal shows nothing, which is the intended fail-closed default
     * but leaves these bulls unreachable from the very screen where the venereal rule is worth
     * exercising. They go to the M.V. who holds a system account (M.P. 4582), so no temporary
     * access link changes scope.
     */
    private function assignToPortalVeterinarian(int $companyId, int $batchId): void
    {
        $veterinarian = Veterinarian::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('user_id')
            ->first();

        if ($veterinarian === null) {
            $this->command?->warn('  Sin veterinario con cuenta: el lote queda sin asignar al portal.');

            return;
        }

        VeterinarianBatchAssignment::withoutGlobalScopes()->updateOrCreate(
            [
                'company_id' => $companyId,
                'veterinarian_id' => $veterinarian->id,
                'batch_id' => $batchId,
                'assigned_at' => now()->toDateString(),
            ],
            ['unassigned_at' => null]
        );

        $this->command?->info(
            '  Lote asignado en el portal a ' . $veterinarian->name . ' (' . $veterinarian->license_number . ').'
        );
    }

    /**
     * An own batch has no farm: `CreateServiceOrderUseCase` rejects any target batch whose
     * `farm_id` is set. The CRIA activity is what makes it usable as the target of a service
     * order, so the same batch serves the sanitary lock tests.
     */
    private function resolveOwnBreedingBatch(int $companyId): Batch
    {
        $criaActivity = Activity::withoutGlobalScopes()->where('code', 'CRIA')->first()
            ?? Activity::create(['code' => 'CRIA', 'name' => 'Cría']);

        return Batch::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'name' => self::BATCH_NAME],
            [
                'farm_id' => null,
                'activity_id' => $criaActivity->id,
                'is_active' => true,
                'is_system' => false,
                'observaciones' => 'Lote propio reservado a pruebas: reproductores sin evaluación física ni muestreo de laboratorio.',
            ]
        );
    }

    /**
     * @return list<int>
     */
    private function resolveBulls(int $companyId, int $batchId): array
    {
        $toroCategory = AnimalCategory::withoutGlobalScopes()->where('code', 'TORO')->first();
        $ids = [];

        foreach (self::TAGS as $index => $tag) {
            $caravan = Caravan::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $companyId, 'identification' => $tag],
                [
                    'batch_id' => $batchId,
                    'sex' => 'M',
                    'category_id' => $toroCategory?->id,
                    'teeth' => 4,
                    'entry_weight' => 700.0 + ($index * 15),
                    'entry_date' => now()->subMonths(2)->toDateString(),
                ]
            );

            $ids[] = (int) $caravan->id;
        }

        return $ids;
    }

    /**
     * @param list<int> $caravanIds
     * @return int Rows removed across the three sanitary tables.
     */
    private function stripSanitaryHistory(int $companyId, array $caravanIds): int
    {
        if ($caravanIds === []) {
            return 0;
        }

        $removed = 0;

        // Order matters: findings reference samples, samples reference evaluations.
        $removed += VeterinaryDiagnosis::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('caravan_id', $caravanIds)
            ->delete();

        $removed += BullLabSample::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('caravan_id', $caravanIds)
            ->delete();

        $removed += BullHealthEvaluation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('caravan_id', $caravanIds)
            ->delete();

        return $removed;
    }
}
