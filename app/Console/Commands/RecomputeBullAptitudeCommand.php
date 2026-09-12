<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Services\RecomputeBullAptitudeBulkService;
use App\Models\BullHealthEvaluation;
use App\Models\Caravan;
use Illuminate\Console\Command;

/**
 * Deployment aid for ADR-4. Activating the venereal sampling rule reclassifies animals that
 * are APT today with zero recorded samples, so the operator must be able to see the impact
 * before committing to it.
 *
 * Rollout: deploy with LIVESTOCK_ENFORCE_VENEREAL_SAMPLING=false, load the historical
 * protocols, run this with --dry-run, then flip the flag and run it for real.
 */
final class RecomputeBullAptitudeCommand extends Command
{
    protected $signature = 'livestock:recompute-bull-aptitude
                            {--company= : Company id to recompute; required}
                            {--dry-run : Report the impact without writing anything}';

    protected $description = 'Recompute reproductive aptitude for every bull of a company under the current sanitary rules';

    public function handle(RecomputeBullAptitudeBulkService $recompute): int
    {
        $companyId = (int) $this->option('company');

        if ($companyId <= 0) {
            $this->error('Debe indicar --company=<id>.');

            return self::FAILURE;
        }

        $caravanIds = Caravan::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('sex', ['M', 'MACHO', 'MALE'])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($caravanIds === []) {
            $this->warn("No se encontraron reproductores para la compañía {$companyId}.");

            return self::SUCCESS;
        }

        $before = BullHealthEvaluation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('caravan_id', $caravanIds)
            ->orderBy('caravan_id')
            ->orderBy('last_evaluation_date')
            ->orderBy('id')
            ->get(['caravan_id', 'status'])
            ->keyBy('caravan_id')
            ->map(static fn ($row): string => (string) $row->status)
            ->all();

        if ($this->option('dry-run')) {
            $this->info('Modo simulación: no se escribe ningún cambio.');
            $this->reportProjectedImpact($recompute, $caravanIds, $companyId, $before);

            return self::SUCCESS;
        }

        $after = $recompute->__invoke($caravanIds, $companyId);
        $this->renderTransitions($before, $after);
        $this->info(sprintf('Recálculo aplicado sobre %d reproductores.', count($caravanIds)));

        return self::SUCCESS;
    }

    /**
     * @param list<int> $caravanIds
     * @param array<int, string> $before
     */
    private function reportProjectedImpact(
        RecomputeBullAptitudeBulkService $recompute,
        array $caravanIds,
        int $companyId,
        array $before
    ): void {
        // The service writes, so the simulation runs inside a rolled back transaction.
        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
            $after = $recompute->__invoke($caravanIds, $companyId);
            $this->renderTransitions($before, $after);
        } finally {
            \Illuminate\Support\Facades\DB::rollBack();
        }
    }

    /**
     * @param array<int, string> $before
     * @param array<int, string> $after
     */
    private function renderTransitions(array $before, array $after): void
    {
        $transitions = [];

        foreach ($after as $caravanId => $newStatus) {
            $oldStatus = $before[$caravanId] ?? 'SIN_EVALUACION';

            if ($oldStatus !== $newStatus) {
                $key = $oldStatus . ' -> ' . $newStatus;
                $transitions[$key] = ($transitions[$key] ?? 0) + 1;
            }
        }

        if ($transitions === []) {
            $this->info('Ningún reproductor cambia de estado.');

            return;
        }

        $this->table(
            ['Transición', 'Reproductores'],
            array_map(
                static fn (string $transition, int $count): array => [$transition, $count],
                array_keys($transitions),
                array_values($transitions)
            )
        );
    }
}
