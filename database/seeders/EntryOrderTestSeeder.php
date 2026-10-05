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
use App\Models\EntryOrderIncident;
use App\Models\EntryOrderReceiptSheet;
use App\Models\Farm;
use App\Models\Provider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Entry orders of external livestock (ING-02) in their useful states, for trying the flow from
 * /batches/external and the entry orders tray:
 *
 *  - EN-20260928-0001 (AWAITING_DTE): 40 Terneros, both sexes (25 machos / 15 hembras), Braford
 *    Colorado + Brangus Negro, auction 338, batch "338-1" empty, waiting for its DTE.
 *  - EN-20260928-0002 (AWAITING_DTE · 6 de 10): 10 Novillitos, machos, Angus Negro, custom name;
 *    its DTE DTE-TEST-ING-001 brought ING-T-01..06, already received; 4 head wait for a DTE.
 *  - EN-20260928-0003 (DRAFT): 20 Vaquillonas, hembras, auction 412, no batch yet.
 *  - EN-20260928-0004 (IN_TRANSIT): 13 Terneros machos, every head with DTE (DTE-TEST-ING-002,
 *    ING-T-11..23): 2 received at the chute, 1 declared missing with its MISSING_HEAD incident
 *    open, 10 still in transit — try "Recibir" here.
 *  - EN-20260928-0005 (IN_TRANSIT, with excess): bought 8 Terneros hembra, the DTE DTE-TEST-ING-003
 *    brought 10 (ING-T-31..40), all in transit, with its EXCESS_HEAD incident open.
 *  - EN-20260928-0006 (IN_TRANSIT, weighed with one average): 8 Terneros machos, Hereford Pampa,
 *    DTE DTE-TEST-ING-004 with ING-T-41..48, all in transit.
 *  - EN-20260928-0007 … 0010 (IN_TRANSIT): one order per blank sheet "para imprimir", so each can be
 *    printed, filled in by hand at the chute and loaded on its own, without touching the others.
 *
 * Every order in transit has its ING-03 receipt sheet R1 issued for its DTE, with the caravans in
 * transit. Each test image of ai-agent/image_test/ing003/ (generate_ing03_images.py) is one of those
 * sheets, and each loadable image has its own order, so they load in any order:
 *
 *    image                                        order              R1                   caravans
 *    01_parcial_con_faltante_y_sin_dte            EN-20260928-0004   per animal           ING-T-14..23
 *    02_invalida_para_reparar (blocks; then 03)   EN-20260928-0005   per animal           ING-T-31..40
 *    03_recepcion_completa                        EN-20260928-0005   per animal           ING-T-31..40
 *    04_para_imprimir (portrait)                  EN-20260928-0007   per animal           ING-T-51..60
 *    05_promedio_horizontal                       EN-20260928-0006   one average          ING-T-41..48
 *    06_para_imprimir_promedio_horizontal         EN-20260928-0008   one average          ING-T-61..68
 *    07_para_imprimir_promedio_vertical           EN-20260928-0009   one average          ING-T-71..78
 *    08_para_imprimir_horizontal                  EN-20260928-0010   per animal           ING-T-81..90
 *
 * The first three take numbers 1 to 3; the others take the next free numbers (4, 5… on a fresh base,
 * later ones if orders were created by hand). Their external batches are named after the code
 * ("338-4" for EN-20260928-0004), the name the images print. To see the
 * name checks: a new order named "338-1" in "Estancia La Porteña (TEST ING)" is refused (same
 * establishment), in "Campo El Ombú (TEST ING)" it is only advised against. Loading ING-T-01
 * again shows the "caravana ya existe" error.
 *
 * Re-running it rebuilds the orders from scratch, no migrate:fresh needed:
 *     php artisan tenants:seed --class=EntryOrderTestSeeder
 */
class EntryOrderTestSeeder extends Seeder
{
    private const PROVIDER_CUIT = '30-71555000-1';
    private const CODES = [
        'EN-20260928-0001', 'EN-20260928-0002', 'EN-20260928-0003', 'EN-20260928-0004', 'EN-20260928-0005', 'EN-20260928-0006',
        'EN-20260928-0007', 'EN-20260928-0008', 'EN-20260928-0009', 'EN-20260928-0010',
    ];
    private const PURCHASE_DATE = '2026-09-28';

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

            // 2. Waiting for documents: one DTE loaded and received, four head without DTE.
            $partialBatch = $this->externalBatch($companyId, (int) $mainFarm->id, 'Compra Directa (TEST ING)', 250, 230, 270, false);
            $partial = EntryOrder::withoutGlobalScopes()->create([
                ...$base,
                'code' => self::CODES[1],
                'number' => 2,
                'status' => 'AWAITING_DTE',
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
            $this->loadDte($partial, $angus, (int) $partialBatch->id, $mainFarm, 'DTE-TEST-ING-001', '2026-09-29', 'M', range(1, 6), array_fill(0, 6, 'RECEIVED'));
            Batch::withoutGlobalScopes()->whereKey($partialBatch->id)->update(['caravans_count' => 6]);
            $this->history($partial, 'AWAITING_DTE', 'AWAITING_DTE', null, ['dte_number' => 'DTE-TEST-ING-001', 'head_count' => 6, 'with_dte_total' => 6, 'pending_dte' => 4]);

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

            // 4. In transit: every head has its DTE; part received, one will not arrive.
            $number = $this->nextNumber($companyId);
            $code = self::CODES[3];
            $transitBatch = $this->externalBatch($companyId, (int) $mainFarm->id, $this->batchName(self::CODES[3]), 185, 165, 205, true);
            $transit = EntryOrder::withoutGlobalScopes()->create([
                ...$base,
                ...$this->calves('MALE', 13),
                'code' => self::CODES[3],
                'number' => $number,
                'status' => 'IN_TRANSIT',
                'auction_number' => '338',
                'batch_id' => $transitBatch->id,
                'batch_name' => $this->batchName($code),
                'batch_name_mode' => 'AUTO',
                'first_dte_at' => now(),
            ]);
            $braford = $this->breeds($transit, [['Braford', 'Colorado']])[0];
            $this->history($transit, null, 'AWAITING_DTE', 'Compra confirmada: en espera de DTE');
            $dte = $this->loadDte($transit, $braford, (int) $transitBatch->id, $mainFarm, 'DTE-TEST-ING-002', '2026-10-01', 'M', range(11, 23), [
                'RECEIVED', 'RECEIVED', 'MISSING', ...array_fill(0, 10, 'PENDING'),
            ]);
            Batch::withoutGlobalScopes()->whereKey($transitBatch->id)->update(['caravans_count' => 2]);
            $this->history($transit, 'AWAITING_DTE', 'IN_TRANSIT', null, ['dte_number' => 'DTE-TEST-ING-002', 'head_count' => 13, 'with_dte_total' => 13, 'pending_dte' => 0]);
            $this->history($transit, 'IN_TRANSIT', 'IN_TRANSIT', 'Murió en el viaje', ['reception' => true, 'method' => 'CHUTE', 'received' => 2, 'missing' => 1]);
            $this->incident($transit, $dte, 'MISSING_HEAD', '1 caravana del DTE DTE-TEST-ING-002 no llegará: ING-T-13. Motivo: Murió en el viaje', [
                'caravans' => ['DTE-TEST-ING-002' => ['ING-T-13']], 'count' => 1, 'reason' => 'Murió en el viaje',
            ]);
            $this->receiptSheet($transit, $dte);

            // 5. In transit with excess: the DTE brought two head more than were bought.
            $number = $this->nextNumber($companyId);
            $code = self::CODES[4];
            $excessBatch = $this->externalBatch($companyId, (int) $mainFarm->id, $this->batchName(self::CODES[4]), 175, 155, 195, true);
            $excess = EntryOrder::withoutGlobalScopes()->create([
                ...$base,
                ...$this->calves('FEMALE', 8),
                'code' => self::CODES[4],
                'number' => $number,
                'status' => 'IN_TRANSIT',
                'auction_number' => '338',
                'batch_id' => $excessBatch->id,
                'batch_name' => $this->batchName($code),
                'batch_name_mode' => 'AUTO',
                'first_dte_at' => now(),
            ]);
            $brangus = $this->breeds($excess, [['Brangus', 'Negro']])[0];
            $this->history($excess, null, 'AWAITING_DTE', 'Compra confirmada: en espera de DTE');
            $dte = $this->loadDte($excess, $brangus, (int) $excessBatch->id, $mainFarm, 'DTE-TEST-ING-003', '2026-10-02', 'H', range(31, 40), array_fill(0, 10, 'PENDING'));
            $this->history($excess, 'AWAITING_DTE', 'IN_TRANSIT', null, ['dte_number' => 'DTE-TEST-ING-003', 'head_count' => 10, 'with_dte_total' => 10, 'pending_dte' => 0, 'incidents' => ['EXCESS_HEAD']]);
            $this->incident($excess, $dte, 'EXCESS_HEAD', 'Orden por 8 cabezas; con el DTE DTE-TEST-ING-003 suman 10 (+2).', [
                'declared' => 8, 'with_dte' => 10, 'excess' => 2,
            ]);
            $this->receiptSheet($excess, $dte);

            // 6. In transit, received with one average weight for the whole arrival.
            $number = $this->nextNumber($companyId);
            $code = self::CODES[5];
            $averageBatch = $this->externalBatch($companyId, (int) $mainFarm->id, $this->batchName(self::CODES[5]), 180, 160, 200, true);
            $average = EntryOrder::withoutGlobalScopes()->create([
                ...$base,
                ...$this->calves('MALE', 8),
                'code' => self::CODES[5],
                'number' => $number,
                'status' => 'IN_TRANSIT',
                'auction_number' => '338',
                'batch_id' => $averageBatch->id,
                'batch_name' => $this->batchName($code),
                'batch_name_mode' => 'AUTO',
                'first_dte_at' => now(),
            ]);
            $hereford = $this->breeds($average, [['Hereford', 'Pampa']])[0];
            $this->history($average, null, 'AWAITING_DTE', 'Compra confirmada: en espera de DTE');
            $dte = $this->loadDte($average, $hereford, (int) $averageBatch->id, $mainFarm, 'DTE-TEST-ING-004', '2026-10-02', 'M', range(41, 48), array_fill(0, 8, 'PENDING'));
            $this->history($average, 'AWAITING_DTE', 'IN_TRANSIT', null, ['dte_number' => 'DTE-TEST-ING-004', 'head_count' => 8, 'with_dte_total' => 8, 'pending_dte' => 0]);
            $this->receiptSheet($average, $dte, 'AVERAGE');

            // 7 to 10. One order per blank sheet to print, fill in at the chute and load.
            $this->printableOrder($companyId, $base, $mainFarm, self::CODES[6], 'DTE-TEST-ING-005', 'MALE', ['Angus', 'Negro'], range(51, 60), 'INDIVIDUAL');
            $this->printableOrder($companyId, $base, $mainFarm, self::CODES[7], 'DTE-TEST-ING-006', 'FEMALE', ['Brangus', 'Negro'], range(61, 68), 'AVERAGE');
            $this->printableOrder($companyId, $base, $mainFarm, self::CODES[8], 'DTE-TEST-ING-007', 'MALE', ['Braford', 'Colorado'], range(71, 78), 'AVERAGE');
            $this->printableOrder($companyId, $base, $mainFarm, self::CODES[9], 'DTE-TEST-ING-008', 'FEMALE', ['Angus', 'Colorado'], range(81, 90), 'INDIVIDUAL');
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

    /**
     * A single-sex troop of calves of one breed, from the same auction.
     *
     * @return array<string, mixed>
     */
    private function calves(string $sex, int $head): array
    {
        return [
            'kind' => 'PLANNED',
            'head_count' => $head,
            'category_id' => $this->categoryId('TERNERO'),
            'sex_composition' => $sex,
            'condition' => 'GOOD',
            'age_min_months' => 8,
            'age_max_months' => 10,
            'knows_to_eat' => true,
            'tick_vaccinated' => true,
            'estimated_weight' => 180,
            'min_weight' => 160,
            'max_weight' => 200,
            'confirmed_at' => now(),
        ];
    }

    /**
     * A DTE with caravans ING-T-{numbers}. Each caravan takes the reception status given by
     * position: a RECEIVED one has its entry date and PURCHASE movement; the others are in transit
     * or will never arrive, so they have neither.
     *
     * @param list<int> $numbers
     * @param list<string> $statuses PENDING | RECEIVED | MISSING, by position
     */
    private function loadDte(EntryOrder $order, int $breedLineId, int $batchId, Farm $farm, string $dteNumber, string $dteDate, string $sex, array $numbers, array $statuses): EntryOrderDte
    {
        $companyId = (int) $order->company_id;
        $receivedAt = date('Y-m-d', strtotime($dteDate . ' +1 day'));
        $breedLine = $order->breeds()->find($breedLineId);

        $dte = EntryOrderDte::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'entry_order_id' => $order->id,
            'dte_number' => $dteNumber,
            'dte_date' => $dteDate,
            'head_count' => count($numbers),
        ]);

        $provenance = [
            'origin_renspa' => $farm->renspa,
            'origin_provider_id' => $order->provider_id,
            'dte_number' => $dteNumber,
            'auction_name' => $order->auction_number,
            'extra_data' => ['entry_order_code' => $order->code, 'source' => 'ENTRY_ORDER'],
        ];

        foreach ($numbers as $i => $n) {
            $status = $statuses[$i];
            $received = $status === 'RECEIVED';

            $caravan = Caravan::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'batch_id' => $batchId,
                'provider_id' => $order->provider_id,
                'renspa' => $farm->renspa,
                'identification' => sprintf('ING-T-%02d', $n),
                'category_id' => $order->category_id,
                'sex' => $sex,
                'teeth' => 0,
                'breed_id' => $breedLine?->breed_id,
                'color_id' => $breedLine?->color_id,
                'entry_date' => $received ? $receivedAt : null,
                'provenance_metadata' => $provenance,
            ]);

            $movement = $received ? CaravanMovement::create([
                'caravan_id' => $caravan->id,
                'company_id' => $companyId,
                'to_batch_id' => $batchId,
                'provider_id' => $order->provider_id,
                'renspa' => $farm->renspa,
                'from_renspa' => $farm->renspa,
                'type' => 'PURCHASE',
                'movement_date' => $receivedAt,
                'provenance_metadata' => $provenance,
                'observations' => "Ingreso por DTE {$dteNumber} de la orden {$order->code}",
            ]) : null;

            EntryOrderAnimal::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'entry_order_id' => $order->id,
                'entry_order_dte_id' => $dte->id,
                'caravan_id' => $caravan->id,
                'reception_status' => $status,
                'received_at' => $received ? $receivedAt : null,
                'reception_method' => $received ? ($dteNumber === 'DTE-TEST-ING-001' ? 'MANUAL' : 'CHUTE') : null,
                'entry_order_breed_id' => $breedLineId,
                'caravan_movement_id' => $movement?->id,
            ]);
        }

        return $dte;
    }

    /**
     * The ING-03 R1 of a DTE: its caravans in transit, in print order, as the order would issue it.
     */
    private function receiptSheet(EntryOrder $order, EntryOrderDte $dte, string $weighingMode = 'INDIVIDUAL'): void
    {
        $caravanIds = EntryOrderAnimal::withoutGlobalScopes()
            ->join('caravans', 'caravans.id', '=', 'entry_order_animals.caravan_id')
            ->where('entry_order_animals.entry_order_dte_id', $dte->id)
            ->where('entry_order_animals.reception_status', 'PENDING')
            ->orderBy('caravans.identification')
            ->pluck('entry_order_animals.caravan_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        EntryOrderReceiptSheet::withoutGlobalScopes()->create([
            'company_id' => $order->company_id,
            'entry_order_id' => $order->id,
            'entry_order_dte_id' => $dte->id,
            'number' => 1,
            'status' => 'ISSUED',
            'weighing_mode' => $weighingMode,
            'caravan_ids' => $caravanIds,
            'page_count' => (int) max(1, ceil((count($caravanIds) + 4) / 20)),
            'processed_pages' => [],
        ]);
        $this->history($order, 'IN_TRANSIT', 'IN_TRANSIT', null, [
            'action' => 'receipt_sheet_issued', 'receipt_sheet' => 'R1', 'dte_number' => $dte->dte_number, 'caravans' => count($caravanIds),
            'weighing_mode' => $weighingMode, 'weighing_mode_label' => $weighingMode === 'AVERAGE' ? 'Peso promedio' : 'Peso individual',
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function incident(EntryOrder $order, EntryOrderDte $dte, string $type, string $detail, array $metadata): void
    {
        EntryOrderIncident::withoutGlobalScopes()->create([
            'company_id' => $order->company_id,
            'entry_order_id' => $order->id,
            'entry_order_dte_id' => $dte->id,
            'type' => $type,
            'detail' => $detail,
            'metadata' => $metadata,
            'status' => 'OPEN',
        ]);
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

    /**
     * An order in transit whose whole DTE is still on the way, with its R1 issued: the paper of a
     * blank test sheet.
     *
     * @param array<string, mixed> $base
     * @param array{0: string, 1: string} $breed
     * @param list<int> $numbers
     */
    private function printableOrder(int $companyId, array $base, Farm $farm, string $code, string $dteNumber, string $sex, array $breed, array $numbers, string $weighingMode): void
    {
        $number = $this->nextNumber($companyId);
        $batch = $this->externalBatch($companyId, (int) $farm->id, $this->batchName($code), 180, 160, 200, true);
        $order = EntryOrder::withoutGlobalScopes()->create([
            ...$base,
            ...$this->calves($sex, count($numbers)),
            'code' => $code,
            'number' => $number,
            'status' => 'IN_TRANSIT',
            'auction_number' => '338',
            'batch_id' => $batch->id,
            'batch_name' => $this->batchName($code),
            'batch_name_mode' => 'AUTO',
            'first_dte_at' => now(),
        ]);
        $breedLine = $this->breeds($order, [$breed])[0];
        $this->history($order, null, 'AWAITING_DTE', 'Compra confirmada: en espera de DTE');
        $dte = $this->loadDte($order, $breedLine, (int) $batch->id, $farm, $dteNumber, '2026-10-02', $sex === 'MALE' ? 'M' : 'H', $numbers, array_fill(0, count($numbers), 'PENDING'));
        $this->history($order, 'AWAITING_DTE', 'IN_TRANSIT', null, ['dte_number' => $dteNumber, 'head_count' => count($numbers), 'with_dte_total' => count($numbers), 'pending_dte' => 0]);
        $this->receiptSheet($order, $dte, $weighingMode);
    }

    /** "338-4" for EN-20260928-0004: the batch name the test images print. */
    private function batchName(string $code): string
    {
        return '338-' . (int) substr($code, -4);
    }

    private function nextNumber(int $companyId): int
    {
        return (int) EntryOrder::withoutGlobalScopes()->where('company_id', $companyId)->max('number') + 1;
    }

    private function categoryId(string $code): int
    {
        return (int) AnimalCategory::withoutGlobalScopes()->where('code', $code)->value('id');
    }
}
