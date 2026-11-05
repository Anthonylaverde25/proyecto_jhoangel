<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\DTOs\ServiceOrders\StartBatchServiceOrderDTO;
use App\Application\UseCases\Batches\StartBatchServiceOrderUseCase;
use App\Core\Enums\ServiceMethod;
use App\Core\Interfaces\ICompanyContext;
use App\Models\Caravan;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The two natural services (monta) of the spring campaign, started through the use case the screen
 * uses: the cows stay in their batch, and two fit bulls leave the Torada to join each rodeo.
 */
class RodeoDemoServicesSeeder extends Seeder
{
    private const SERVICES = [
        RodeoDemoSeeder::BATCH_NORTE => ['2026-10-01', '2026-12-30'],
        RodeoDemoSeeder::BATCH_SUR => ['2026-10-15', '2027-01-15'],
    ];

    private const BULLS_PER_SERVICE = 2;

    public function run(): void
    {
        $company = Company::query()->orderBy('id')->first();
        $userId = User::query()->value('id');
        if ($company === null || $userId === null) {
            $this->command?->warn('RodeoDemoServicesSeeder: faltan la compañía o el usuario. Se omite.');

            return;
        }

        $companyId = (int) $company->id;
        app(ICompanyContext::class)->setCompanyId($companyId);

        // By the verdict in force: an evaluation from past campaigns does not make a bull fit today.
        $bulls = Caravan::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('batch_id', DB::table('batches')->where('company_id', $companyId)->where('name', RodeoDemoSeeder::BATCH_BULLS)->value('id'))
            ->where('is_breeding_eligible', true)
            ->with('bullHealthEvaluation')
            ->orderBy('id')
            ->get()
            ->filter(static fn (Caravan $bull): bool => $bull->bullHealthEvaluation?->status === 'APT')
            ->map(static fn (Caravan $bull): int => (int) $bull->id)
            ->values()
            ->all();

        $start = app(StartBatchServiceOrderUseCase::class);

        foreach (self::SERVICES as $batchName => [$from, $until]) {
            $start(new StartBatchServiceOrderDTO(
                batchId: (int) DB::table('batches')->where('company_id', $companyId)->where('name', $batchName)->value('id'),
                companyId: $companyId,
                userId: (int) $userId,
                maleCaravanIds: array_splice($bulls, 0, self::BULLS_PER_SERVICE),
                plannedStartDate: $from,
                plannedEndDate: $until,
                serviceMethod: ServiceMethod::NATURAL,
            ));
        }
    }
}
