<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\DTOs\Inseminations\InseminationRowDTO;
use App\Application\DTOs\Inseminations\RegisterInseminationsDTO;
use App\Application\DTOs\ServiceOrders\StartBatchServiceOrderDTO;
use App\Application\UseCases\Batches\StartBatchServiceOrderUseCase;
use App\Application\UseCases\ServiceOrders\RegisterInseminationsUseCase;
use App\Core\Enums\BatchWeightCause;
use App\Core\Enums\ServiceMethod;
use App\Core\Services\BatchWeightService;
use App\Core\Exceptions\DomainException;
use App\Models\Activity;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Caravan;
use App\Models\Company;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AI service order scenario (semen S-B, plan part 5 §5.1), first company, fake data, through the use
 * cases. Creates only what is missing: a batch ready to start an AI (10 empty cows, one excluded) and
 * a batch already inseminating (8 cows, Rambo convencional planned for 7, 3 inseminations from L-2301
 * in TER-01). Needs SemenStockTestSeeder.
 */
class InseminationServiceTestSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->orderBy('id')->first();
        $tank = $company === null ? null : Warehouse::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'TER-01')->first();
        $semen = $company === null ? null : DB::table('item_semen_details as d')
            ->join('semen_sires as s', 's.id', '=', 'd.semen_sire_id')
            ->where('d.company_id', $company->id)->where('s.registry_code', 'HBA-1001')->where('d.semen_type', 'CONVENTIONAL')
            ->value('d.item_id');
        if ($company === null || $tank === null || $semen === null) {
            $this->command?->warn('InseminationServiceTestSeeder: falta el semen de SemenStockTestSeeder. Se omite.');

            return;
        }
        $companyId = (int) $company->id;

        $ready = $this->batch($companyId, 'IA Vaquillonas 2026');
        $this->cows($companyId, $ready, 'IA-VQ', 10);
        Caravan::withoutGlobalScopes()->where('company_id', $companyId)->where('identification', 'IA-VQ-10')
            ->update(['is_breeding_eligible' => false, 'breeding_exclusion_reason' => 'CUT_DENTITION']);

        $running = $this->batch($companyId, 'IA Rodeo Demo');
        $cows = $this->cows($companyId, $running, 'IA-RD', 8);
        // Seeders run without model events: the batch aggregates (heads, weight) are recomputed here.
        foreach ([$ready, $running] as $batch) {
            app(BatchWeightService::class)->recalculateBatchWeight((int) $batch->id, BatchWeightCause::MOVEMENT_IN);
        }
        if (ServiceOrder::withoutGlobalScopes()->where('batch_id', $running->id)->exists()) {
            return;
        }

        $userId = (int) (User::query()->orderBy('id')->value('id') ?? 1);
        $start = now()->subDays(10)->toDateString();
        try {
            DB::transaction(function () use ($companyId, $running, $cows, $semen, $tank, $userId, $start): void {
                $order = app(StartBatchServiceOrderUseCase::class)(new StartBatchServiceOrderDTO(
                    batchId: (int) $running->id,
                    companyId: $companyId,
                    userId: $userId,
                    maleCaravanIds: [],
                    selectedFemaleCaravanIds: $cows,
                    plannedStartDate: $start,
                    observations: 'IATF de prueba (seeder S-B).',
                    serviceMethod: ServiceMethod::AI,
                    plannedSemen: array_fill_keys(array_slice($cows, 0, 7), (int) $semen),
                ));
                $lot = (int) DB::table('semen_lots')->where('item_id', $semen)->where('code', 'L-2301')->value('id');
                app(RegisterInseminationsUseCase::class)(new RegisterInseminationsDTO(
                    (int) $order->getId(),
                    $companyId,
                    $userId,
                    'seed-sb-01',
                    array_map(fn (int $cow) => new InseminationRowDTO($cow, now()->subDays(9)->toDateString(), (int) $semen, $lot, 1, (int) $tank->id), array_slice($cows, 0, 3)),
                ));
            });
        } catch (DomainException $e) {
            $this->command?->warn("InseminationServiceTestSeeder: {$e->getMessage()}");
        }
    }

    private function batch(int $companyId, string $name): Batch
    {
        return Batch::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId, 'name' => $name],
            [
                'activity_id' => Activity::withoutGlobalScopes()->where('code', 'CRIA')->value('id'),
                'batch_type_id' => BatchType::withoutGlobalScopes()->where('code', 'OPERATIONAL')->value('id'),
                'is_confined' => false,
                'is_active' => true,
                'is_system' => false,
                'observaciones' => 'Escenario de prueba de inseminación artificial (S-B).',
            ]
        );
    }

    /** @return list<int> */
    private function cows(int $companyId, Batch $batch, string $prefix, int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $caravan = Caravan::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $companyId, 'identification' => sprintf('%s-%02d', $prefix, $i)],
                [
                    'batch_id' => $batch->id,
                    'sex' => 'H',
                    'teeth' => 4,
                    'entry_weight' => 380,
                    'entry_date' => '2025-09-01',
                    // Explicit: seeders run without model events.
                    'is_breeding_eligible' => true,
                    'breeding_exclusion_reason' => null,
                ]
            );
            $ids[] = (int) $caravan->id;
        }

        return $ids;
    }
}
