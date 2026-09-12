<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\Services\RecomputeBullAptitudeBulkService;
use App\Models\Caravan;
use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * Closes the seeding chain: once the protocols exist and every determination carries its
 * pathogen, aptitude is recomputed under the venereal rule of ADR-4. Otherwise the seeded
 * statuses would keep the values BullHealthSeeder hardcoded, which no longer match the rules.
 */
class BullAptitudeRecalculationSeeder extends Seeder
{
    public function run(RecomputeBullAptitudeBulkService $recompute): void
    {
        $company = Company::first();

        if (!$company) {
            return;
        }

        $companyId = (int) $company->id;

        $caravanIds = Caravan::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('sex', ['M', 'MACHO', 'MALE'])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($caravanIds === []) {
            return;
        }

        $statuses = $recompute->__invoke($caravanIds, $companyId);
        $summary = array_count_values($statuses);

        foreach ($summary as $status => $count) {
            $this->command?->info("Aptitud recalculada — {$status}: {$count} reproductores.");
        }
    }
}
