<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\Breed;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\Color;
use App\Models\Company;
use App\Models\EntryOrder;
use App\Models\EntryOrderAnimal;
use App\Models\EntryOrderDte;
use App\Models\Farm;
use App\Models\Provider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Entry orders of external livestock (ING-02) in their three useful states, for trying the flow
 * from /batches/external and the entry orders tray:
 *
 *  - EN-20260928-0001 (AWAITING_DTE): 40 Terneros, both sexes (25 machos / 15 hembras), Braford
 *    Colorado + Brangus Negro, auction 338, batch "338-1" empty, waiting for its DTE.
 *  - EN-20260928-0002 (PARTIAL): 10 Novillitos, machos, Angus Negro, custom name; its DTE
 *    DTE-TEST-ING-001 brought ING-T-01..06, so 4 head are still pending.
 *  - EN-20260928-0003 (DRAFT): 20 Vaquillonas, hembras, auction 412, no batch yet.
 *
 * They take numbers 1 to 3, so the next order created by hand is number 4 ("338-4"). To see the
 * name checks: a new order named "338-1" in "Estancia La Porteña (TEST ING)" is refused (same
 * establishment), in "Campo El Ombú (TEST ING)" it is only advised against. Loading ING-T-01
 * again shows the "caravana ya existe" error.
 *
 * Re-running it rebuilds the three orders from scratch, no migrate:fresh needed:
 *     php artisan tenants:seed --class=EntryOrderTestSeeder
 */
class EntryOrderTestSeeder extends Seeder
{
    private const PROVIDER_CUIT = '30-71555000-1';
    private const CODES = ['EN-20260928-0001', 'EN-20260928-0002', 'EN-20260928-0003'];
    private const PURCHASE_DATE = '2026-09-28';
    private const DTE_NUMBER = 'DTE-TEST-ING-001';

    public function run(): void
    {
        $company = Company::first();

        if (!$company) {
            $this->command?->warn('EntryOrderTestSeeder: no hay compañía cargada. Se omite.');

            return;
        }

        $companyId = (int) $company->id;
        DB::transaction(function () use ($companyId): void {
            $this->reset($companyId);

            $provider = Provider::updateOrCreate(
                ['cuit' => self::PROVIDER_CUIT],
                ['name' => 'Consignataria Test Ingreso', 'commercial_name' => 'Remates Test', 'is_active' => true]
            );
            $mainFarm = $this->farm($companyId, (int) $provider->id, 'Estancia La Porteña (TEST ING)', '02.345.6.78901/00');
            $this->farm($companyId, (int) $provider->id, 'Campo El Ombú (TEST ING)', '02.345.6.78901/01');

            $base = [
                'company_id' => $companyId,
                'provider_id' => $provider->id,
                'farm_id' => $mainFarm->id,
                'purchase_date' => self::PURCHASE_DATE,
                'responsable' => 'Encargado de prueba',
                'requested_by_user_id' => null,
            ];

            // 1. Waiting for its DTE.
            $waitingBatch = $this->externalBatch($companyId, (int) $mainFarm->id, '338-1', 180, 160, 200, true);
            $waiting = EntryOrder::withoutGlobalScopes()->create([
                ...$base,
                'code' => self::CODES[0],
                'number' => 1,
                'status' => 'AWAITING_DTE',
                'kind' => 'PLANNED',
                'auction_number' => '338',
                'batch_id' => $waitingBatch->id,
                'batch_name' => '338-1',
                'batch_name_mode' => 'AUTO',
                'head_count' => 40,
                'category_id' => $this->categoryId('TERNERO'),
                'sex_composition' => 'MIXED',
                'male_count' => 25,
                'female_count' => 15,
                'condition' => 'GOOD',
                'age_min_months' => 9,
                'age_max_months' => 10,
                'knows_to_eat' => true,
                'tick_vaccinated' => true,
                'shrink_percent' => 3.5,
                'estimated_weight' => 180,
                'min_weight' => 160,
                'max_weight' => 200,
                'confirmed_at' => now(),
                'observations' => 'Tropa pareja, llega en dos jaulas.',
            ]);
            $this->breeds($waiting, [['Braford', 'Colorado'], ['Brangus', 'Negro']]);
            $this->history($waiting, null, 'AWAITING_DTE', 'Compra confirmada: en espera de DTE');

            // 2. Partial: one DTE loaded, four head pending.
            $partialBatch = $this->externalBatch($companyId, (int) $mainFarm->id, 'Compra Directa (TEST ING)', 250, 230, 270, false);
            $partial = EntryOrder::withoutGlobalScopes()->create([
                ...$base,
                'code' => self::CODES[1],
                'number' => 2,
                'status' => 'PARTIAL',
                'kind' => 'PLANNED',
                'auction_number' => null,
                'batch_id' => $partialBatch->id,
                'batch_name' => 'Compra Directa (TEST ING)',
                'batch_name_mode' => 'CUSTOM',
                'head_count' => 10,
                'category_id' => $this->categoryId('NOVILLITO'),
                'sex_composition' => 'MALE',
                'condition' => 'VERY_GOOD',
                'age_min_months' => 12,
                'age_max_months' => 14,
                'knows_to_eat' => false,
                'tick_vaccinated' => false,
                'estimated_weight' => 250,
                'min_weight' => 230,
                'max_weight' => 270,
                'confirmed_at' => now(),
                'first_dte_at' => now(),
            ]);
            $angus = $this->breeds($partial, [['Angus', 'Negro']])[0];
            $this->history($partial, null, 'AWAITING_DTE', 'Compra confirmada: en espera de DTE');
            $this->loadDte($companyId, $partial, $angus, (int) $partialBatch->id, $mainFarm);

            // 3. Draft: no batch until confirmed.
            $draft = EntryOrder::withoutGlobalScopes()->create([
                ...$base,
                'code' => self::CODES[2],
                'number' => 3,
                'status' => 'DRAFT',
                'kind' => 'PLANNED',
                'auction_number' => '412',
                'batch_id' => null,
                'batch_name' => '412-3',
                'batch_name_mode' => 'AUTO',
                'head_count' => 20,
                'category_id' => $this->categoryId('VAQUILLONA'),
                'sex_composition' => 'FEMALE',
                'condition' => 'EXCELLENT',
                'knows_to_eat' => true,
                'tick_vaccinated' => true,
                'estimated_weight' => 300,
            ]);
            $this->breeds($draft, [['Hereford', 'Pampa']]);
            $this->history($draft, null, 'DRAFT', 'Borrador de orden de ingreso guardado');
        });

        $this->command?->info('EntryOrderTestSeeder: órdenes ' . implode(', ', self::CODES) . ' listas.');
    }

    /**
     * Erases what a previous run left: the three orders, the caravans their DTE created and the
     * batches they own.
     */
    private function reset(int $companyId): void
    {
        $orders = EntryOrder::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('code', self::CODES)->get();
        $caravanIds = EntryOrderAnimal::withoutGlobalScopes()->whereIn('entry_order_id', $orders->pluck('id'))->pluck('caravan_id');
        $batchIds = $orders->pluck('batch_id')->filter();

        foreach ($orders as $order) {
            $order->delete();
        }

        Caravan::withoutGlobalScopes()->whereIn('id', $caravanIds)->delete();
        Caravan::withoutGlobalScopes()->where('identification', 'like', 'ING-T-%')->delete();
        Batch::withoutGlobalScopes()->whereIn('id', $batchIds)->delete();
    }

    private function farm(int $companyId, int $providerId, string $name, string $renspa): Farm
    {
        return Farm::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'provider_id' => $providerId, 'name' => $name],
            ['renspa' => $renspa, 'location' => 'Origen Proveedor', 'is_active' => true]
        );
    }

    private function externalBatch(int $companyId, int $farmId, string $name, float $weight, float $min, float $max, bool $knowsToEat): Batch
    {
        return Batch::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'farm_id' => $farmId,
            'name' => $name,
            'activity_id' => null,
            'batch_type_id' => null,
            'is_confined' => null,
            'is_active' => true,
            'current_weight' => null,
            'min_weight' => $min,
            'max_weight' => $max,
            'knows_to_eat' => $knowsToEat,
            'caravans_count' => 0,
            'observaciones' => "Lote externo de prueba (peso aproximado de compra: {$weight} kg).",
        ]);
    }

    /**
     * @param list<array{0: string, 1: ?string}> $lines breed name, colour name
     * @return list<int> the ids of the breed lines, by position
     */
    private function breeds(EntryOrder $order, array $lines): array
    {
        $ids = [];

        foreach ($lines as $i => [$breed, $color]) {
            $ids[] = (int) $order->breeds()->create([
                'company_id' => $order->company_id,
                'breed_id' => Breed::where('name', $breed)->value('id'),
                'color_id' => $color !== null ? Color::where('name', $color)->value('id') : null,
                'position' => $i + 1,
            ])->id;
        }

        return $ids;
    }

    private function loadDte(int $companyId, EntryOrder $order, int $breedLineId, int $batchId, Farm $farm): void
    {
        $dte = EntryOrderDte::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'entry_order_id' => $order->id,
            'dte_number' => self::DTE_NUMBER,
            'dte_date' => '2026-09-29',
            'entered_at' => '2026-09-30',
            'head_count' => 6,
        ]);

        $provenance = [
            'origin_renspa' => $farm->renspa,
            'origin_provider_id' => $order->provider_id,
            'dte_number' => self::DTE_NUMBER,
            'extra_data' => ['entry_order_code' => $order->code, 'source' => 'ENTRY_ORDER'],
        ];

        for ($n = 1; $n <= 6; $n++) {
            $caravan = Caravan::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'batch_id' => $batchId,
                'provider_id' => $order->provider_id,
                'renspa' => $farm->renspa,
                'identification' => sprintf('ING-T-%02d', $n),
                'category_id' => $order->category_id,
                'sex' => 'M',
                'teeth' => 0,
                'breed_id' => Breed::where('name', 'Angus')->value('id'),
                'color_id' => Color::where('name', 'Negro')->value('id'),
                'entry_date' => '2026-09-30',
                'provenance_metadata' => $provenance,
            ]);

            $movement = CaravanMovement::create([
                'caravan_id' => $caravan->id,
                'company_id' => $companyId,
                'to_batch_id' => $batchId,
                'provider_id' => $order->provider_id,
                'renspa' => $farm->renspa,
                'from_renspa' => $farm->renspa,
                'type' => 'PURCHASE',
                'movement_date' => '2026-09-30',
                'provenance_metadata' => $provenance,
                'observations' => 'Ingreso por DTE ' . self::DTE_NUMBER . " de la orden {$order->code}",
            ]);

            EntryOrderAnimal::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'entry_order_id' => $order->id,
                'entry_order_dte_id' => $dte->id,
                'caravan_id' => $caravan->id,
                'entry_order_breed_id' => $breedLineId,
                'caravan_movement_id' => $movement->id,
            ]);
        }

        Batch::withoutGlobalScopes()->whereKey($batchId)->update(['caravans_count' => 6]);
        $this->history($order, 'AWAITING_DTE', 'PARTIAL', null, ['dte_number' => self::DTE_NUMBER, 'head_count' => 6, 'pending' => 4]);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function history(EntryOrder $order, ?string $from, string $to, ?string $reason, ?array $metadata = null): void
    {
        $order->history()->create([
            'company_id' => $order->company_id,
            'from_status' => $from,
            'to_status' => $to,
            'action_reason' => $reason,
            'action_metadata' => $metadata,
        ]);
    }

    private function categoryId(string $code): int
    {
        return (int) AnimalCategory::withoutGlobalScopes()->where('code', $code)->value('id');
    }
}
