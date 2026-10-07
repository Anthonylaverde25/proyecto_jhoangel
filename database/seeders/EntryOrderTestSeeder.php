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
 *    its DTE DTE-TEST-ING-001 declared 6 head, received as ING-T-01..06; 4 head wait for a DTE.
 *  - EN-20260928-0003 (DRAFT): 20 Vaquillonas, hembras, auction 412, no batch yet.
 *  - EN-20260928-0004 (IN_TRANSIT): 13 Terneros machos, every head with DTE (DTE-TEST-ING-002
 *    declares 13): ING-T-11 and ING-T-12 received, 1 head declared missing with its MISSING_HEAD
 *    incident open, 10 head still in transit — try "Recibir" here.
 *  - EN-20260928-0005 (IN_TRANSIT, both sexes, with excess): bought 8 Terneros (4 machos / 4
 *    hembras), the DTE DTE-TEST-ING-003 declares 10, all in transit, with its EXCESS_HEAD open.
 *  - EN-20260928-0006 (IN_TRANSIT, weighed with one average): 8 Terneros machos, Hereford Pampa,
 *    DTE DTE-TEST-ING-004 declares 8, all in transit.
 *  - EN-20260928-0007 … 0010 (IN_TRANSIT): one order per blank sheet "para imprimir", so each can be
 *    printed, filled in by hand at the chute and loaded on its own, without touching the others.
 *  - EN-20260928-0011 (IN_TRANSIT, several categories): 6 Novillito + 4 Torito + 5 Vaquillona, both
 *    sexes (10 machos / 5 hembras), DTE DTE-TEST-ING-009 declares 15, all in transit. A female can
 *    only be a Vaquillona; a male is a Novillito or a Torito, so its line says which: its R1 prints
 *    the CAT column, by number (its R1 is by code).
 *  - EN-20260928-0012 (IN_TRANSIT, breed and category written): 8 Novillito + 4 Torito, machos,
 *    Braford Colorado + Brangus Negro, DTE DTE-TEST-ING-010 declares 12, all in transit. Its R1 is
 *    written in words: RAZA, PELAJE and CATEGORÍA columns, so it prints in landscape only.
 *  - EN-20260928-0013 (IN_TRANSIT): 8 Terneros machos, Angus Negro, DTE DTE-TEST-ING-011 declares 8,
 *    all in transit: the sheet whose animals come off the truck with injuries (OJO / OREJA / APLOMO).
 *
 * Head in transit have no caravan: it is written down on the ING-03 when the animal arrives. Every
 * order in transit has its R1 issued for its DTE — a blank line per head in transit plus four free
 * lines. Each test image of ai-agent/image_test/ing003/ (generate_ing03_images.py) is one of those
 * sheets filled in, and each loadable image has its own order, so they load in any order:
 *
 *    image                                        order              R1             written on it
 *    01_parcial_llegan_despues                    EN-20260928-0004   per animal     ING-T-14..21 (2 arrive later)
 *    02_invalida_para_reparar (blocks; then 03)   EN-20260928-0005   per animal     repeated tag, blank sex, EC 6
 *    03_recepcion_completa                        EN-20260928-0005   per animal     ING-T-31..40, M and H
 *    04_para_imprimir (portrait)                  EN-20260928-0007   per animal     blank
 *    05_promedio_horizontal                       EN-20260928-0006   one average    ING-T-41..48
 *    06_para_imprimir_promedio_horizontal         EN-20260928-0008   one average    blank
 *    07_para_imprimir_promedio_vertical           EN-20260928-0009   one average    blank
 *    08_para_imprimir_horizontal                  EN-20260928-0010   per animal     blank
 *    09_razas_escritas (landscape, in words)      EN-20260928-0012   per animal     ING-T-71..80, breed, coat and category written
 *    10_lesiones_al_arribo                        EN-20260928-0013   per animal     ING-T-81..88, some boxes marked
 *    11_categoria_por_renglon (by code)           EN-20260928-0011   per animal     ING-T-51..65, CAT. 1 or 2 on the males
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
        'EN-20260928-0007', 'EN-20260928-0008', 'EN-20260928-0009', 'EN-20260928-0010', 'EN-20260928-0011',
        'EN-20260928-0012', 'EN-20260928-0013',
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
            $this->categories($waiting, [['TERNERO', 40]]);
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
            $this->categories($partial, [['NOVILLITO', 10]]);
            $angus = $this->breeds($partial, [['Angus', 'Negro']])[0];
            $this->history($partial, null, 'AWAITING_DTE', 'Compra confirmada: en espera de DTE');
            $this->loadDte($partial, $angus, (int) $partialBatch->id, $mainFarm, 'DTE-TEST-ING-001', '2026-09-29', 'M', 6, range(1, 6));
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
                'sex_composition' => 'FEMALE',
                'condition' => 'EXCELLENT',
                'knows_to_eat' => true,
                'tick_vaccinated' => true,
                'estimated_weight' => 300,
            ]);
            $this->categories($draft, [['VAQUILLONA', 20]]);
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
            $this->categories($transit, [['TERNERO', 13]]);
            $braford = $this->breeds($transit, [['Braford', 'Colorado']])[0];
            $this->history($transit, null, 'AWAITING_DTE', 'Compra confirmada: en espera de DTE');
            $dte = $this->loadDte($transit, $braford, (int) $transitBatch->id, $mainFarm, 'DTE-TEST-ING-002', '2026-10-01', 'M', 13, [11, 12], 1);
            Batch::withoutGlobalScopes()->whereKey($transitBatch->id)->update(['caravans_count' => 2]);
            $this->history($transit, 'AWAITING_DTE', 'IN_TRANSIT', null, ['dte_number' => 'DTE-TEST-ING-002', 'head_count' => 13, 'with_dte_total' => 13, 'pending_dte' => 0]);
            $this->history($transit, 'IN_TRANSIT', 'IN_TRANSIT', 'Murió en el viaje', ['reception' => true, 'method' => 'MANUAL', 'dte_number' => 'DTE-TEST-ING-002', 'received' => 2, 'missing' => 1]);
            $this->incident($transit, $dte, 'MISSING_HEAD', '1 cabeza del DTE DTE-TEST-ING-002 no llegará. Motivo: Murió en el viaje', [
                'count' => 1, 'reason' => 'Murió en el viaje',
            ]);
            $this->receiptSheet($transit, $dte);

            // 5. In transit with excess, both sexes: the DTE declares two head more than were bought.
            $number = $this->nextNumber($companyId);
            $code = self::CODES[4];
            $excessBatch = $this->externalBatch($companyId, (int) $mainFarm->id, $this->batchName(self::CODES[4]), 175, 155, 195, true);
            $excess = EntryOrder::withoutGlobalScopes()->create([
                ...$base,
                ...$this->calves('MIXED', 8),
                'male_count' => 4,
                'female_count' => 4,
                'code' => self::CODES[4],
                'number' => $number,
                'status' => 'IN_TRANSIT',
                'auction_number' => '338',
                'batch_id' => $excessBatch->id,
                'batch_name' => $this->batchName($code),
                'batch_name_mode' => 'AUTO',
                'first_dte_at' => now(),
            ]);
            $this->categories($excess, [['TERNERO', 8]]);
            $brangus = $this->breeds($excess, [['Brangus', 'Negro']])[0];
            $this->history($excess, null, 'AWAITING_DTE', 'Compra confirmada: en espera de DTE');
            $dte = $this->loadDte($excess, $brangus, (int) $excessBatch->id, $mainFarm, 'DTE-TEST-ING-003', '2026-10-02', 'H', 10);
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
            $this->categories($average, [['TERNERO', 8]]);
            $hereford = $this->breeds($average, [['Hereford', 'Pampa']])[0];
            $this->history($average, null, 'AWAITING_DTE', 'Compra confirmada: en espera de DTE');
            $dte = $this->loadDte($average, $hereford, (int) $averageBatch->id, $mainFarm, 'DTE-TEST-ING-004', '2026-10-02', 'M', 8);
            $this->history($average, 'AWAITING_DTE', 'IN_TRANSIT', null, ['dte_number' => 'DTE-TEST-ING-004', 'head_count' => 8, 'with_dte_total' => 8, 'pending_dte' => 0]);
            $this->receiptSheet($average, $dte, 'AVERAGE');

            // 7 to 10. One order per blank sheet to print, fill in at the chute and load.
            $this->printableOrder($companyId, $base, $mainFarm, self::CODES[6], 'DTE-TEST-ING-005', 'MALE', ['Angus', 'Negro'], 10, 'INDIVIDUAL');
            $this->printableOrder($companyId, $base, $mainFarm, self::CODES[7], 'DTE-TEST-ING-006', 'FEMALE', ['Brangus', 'Negro'], 8, 'AVERAGE');
            $this->printableOrder($companyId, $base, $mainFarm, self::CODES[8], 'DTE-TEST-ING-007', 'MALE', ['Braford', 'Colorado'], 8, 'AVERAGE');
            $this->printableOrder($companyId, $base, $mainFarm, self::CODES[9], 'DTE-TEST-ING-008', 'FEMALE', ['Angus', 'Colorado'], 10, 'INDIVIDUAL');

            // 11. Several categories: the sex of a male does not tell its category.
            $this->severalCategoriesOrder($companyId, $base, $mainFarm, self::CODES[10], 'DTE-TEST-ING-009');

            // 12. Breed, coat and category written in words on the sheet.
            $this->writtenReferencesOrder($companyId, $base, $mainFarm, self::CODES[11], 'DTE-TEST-ING-010');

            // 13. Animals that come off the truck injured.
            $this->printableOrder($companyId, $base, $mainFarm, self::CODES[12], 'DTE-TEST-ING-011', 'MALE', ['Angus', 'Negro'], 8, 'INDIVIDUAL');
        });

        $this->command?->info('EntryOrderTestSeeder: órdenes ' . implode(', ', self::CODES) . ' listas.');
    }

    /**
     * Erases what a previous run left: the orders, the caravans received on them and the batches
     * they own.
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
     * The categories bought, each with its head; the order's head_count is their sum.
     *
     * @param list<array{0: string, 1: int}> $lines category code, head
     */
    private function categories(EntryOrder $order, array $lines): void
    {
        foreach ($lines as $i => [$code, $head]) {
            $order->categories()->create([
                'company_id' => $order->company_id,
                'category_id' => $this->categoryId($code),
                'head_count' => $head,
                'position' => $i + 1,
            ]);
        }
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
     * A DTE declaring `$head` head, and the caravans ING-T-{received} already received on it — with
     * their entry date and PURCHASE movement. The rest are in transit, but for `$missing` head
     * declared as never arriving. Head in transit have no caravan: it is written down on arrival.
     *
     * @param list<int> $received
     */
    private function loadDte(EntryOrder $order, int $breedLineId, int $batchId, Farm $farm, string $dteNumber, string $dteDate, string $sex, int $head, array $received = [], int $missing = 0): EntryOrderDte
    {
        $companyId = (int) $order->company_id;
        $receivedAt = date('Y-m-d', strtotime($dteDate . ' +1 day'));
        $breedLine = $order->breeds()->find($breedLineId);
        // The orders with received caravans declare a single category: every caravan is of it.
        $categoryLine = $order->categories()->first();

        $dte = EntryOrderDte::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'entry_order_id' => $order->id,
            'dte_number' => $dteNumber,
            'dte_date' => $dteDate,
            'head_count' => $head,
            'missing_head_count' => $missing,
        ]);

        $provenance = [
            'origin_renspa' => $farm->renspa,
            'origin_provider_id' => $order->provider_id,
            'dte_number' => $dteNumber,
            'auction_name' => $order->auction_number,
            'extra_data' => ['entry_order_code' => $order->code, 'source' => 'ENTRY_ORDER'],
        ];

        foreach ($received as $n) {
            $caravan = Caravan::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'batch_id' => $batchId,
                'provider_id' => $order->provider_id,
                'renspa' => $farm->renspa,
                'identification' => sprintf('ING-T-%02d', $n),
                'category_id' => $categoryLine?->category_id,
                'sex' => $sex,
                'teeth' => 0,
                'breed_id' => $breedLine?->breed_id,
                'color_id' => $breedLine?->color_id,
                'entry_date' => $receivedAt,
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
                'movement_date' => $receivedAt,
                'provenance_metadata' => $provenance,
                'observations' => "Ingreso por DTE {$dteNumber} de la orden {$order->code}",
            ]);

            EntryOrderAnimal::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'entry_order_id' => $order->id,
                'entry_order_dte_id' => $dte->id,
                'caravan_id' => $caravan->id,
                'received_at' => $receivedAt,
                'reception_method' => 'MANUAL',
                'entry_order_breed_id' => $breedLineId,
                'entry_order_category_id' => $categoryLine?->id,
                'caravan_movement_id' => $movement->id,
            ]);
        }

        return $dte;
    }

    /**
     * The ING-03 R1 of a DTE, as the order would issue it: a blank line per head in transit and
     * four free lines, its breed and category written in words (as a first sheet is) or by code.
     */
    private function receiptSheet(EntryOrder $order, EntryOrderDte $dte, string $weighingMode = 'INDIVIDUAL', string $referenceMode = 'WRITTEN'): void
    {
        $received = EntryOrderAnimal::withoutGlobalScopes()->where('entry_order_dte_id', $dte->id)->count();
        $expected = max(0, (int) $dte->head_count - $received - (int) $dte->missing_head_count);
        $rows = $expected + 4;

        EntryOrderReceiptSheet::withoutGlobalScopes()->create([
            'company_id' => $order->company_id,
            'entry_order_id' => $order->id,
            'entry_order_dte_id' => $dte->id,
            'number' => 1,
            'status' => 'ISSUED',
            'weighing_mode' => $weighingMode,
            'reference_mode' => $referenceMode,
            'dte_head_count' => (int) $dte->head_count,
            'expected_head_count' => $expected,
            'row_count' => $rows,
            'page_count' => (int) max(1, ceil($rows / 20)),
            'processed_pages' => [],
        ]);
        $this->history($order, 'IN_TRANSIT', 'IN_TRANSIT', null, [
            'action' => 'receipt_sheet_issued', 'receipt_sheet' => 'R1', 'dte_number' => $dte->dte_number, 'expected_head_count' => $expected,
            'weighing_mode' => $weighingMode, 'weighing_mode_label' => $weighingMode === 'AVERAGE' ? 'Peso promedio' : 'Peso individual',
            'reference_mode' => $referenceMode, 'reference_mode_label' => $referenceMode === 'CODE' ? 'Raza y categoría por código' : 'Raza y categoría escritas',
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
     */
    private function printableOrder(int $companyId, array $base, Farm $farm, string $code, string $dteNumber, string $sex, array $breed, int $head, string $weighingMode): void
    {
        $number = $this->nextNumber($companyId);
        $batch = $this->externalBatch($companyId, (int) $farm->id, $this->batchName($code), 180, 160, 200, true);
        $order = EntryOrder::withoutGlobalScopes()->create([
            ...$base,
            ...$this->calves($sex, $head),
            'code' => $code,
            'number' => $number,
            'status' => 'IN_TRANSIT',
            'auction_number' => '338',
            'batch_id' => $batch->id,
            'batch_name' => $this->batchName($code),
            'batch_name_mode' => 'AUTO',
            'first_dte_at' => now(),
        ]);
        $this->categories($order, [['TERNERO', $head]]);
        $breedLine = $this->breeds($order, [$breed])[0];
        $this->history($order, null, 'AWAITING_DTE', 'Compra confirmada: en espera de DTE');
        $dte = $this->loadDte($order, $breedLine, (int) $batch->id, $farm, $dteNumber, '2026-10-02', $sex === 'MALE' ? 'M' : 'H', $head);
        $this->history($order, 'AWAITING_DTE', 'IN_TRANSIT', null, ['dte_number' => $dteNumber, 'head_count' => $head, 'with_dte_total' => $head, 'pending_dte' => 0]);
        $this->receiptSheet($order, $dte, $weighingMode);
    }

    /**
     * 6 Novillito + 4 Torito + 5 Vaquillona, in transit with its R1 issued by code: the sheet with
     * the CAT column and the category numbers.
     *
     * @param array<string, mixed> $base
     */
    private function severalCategoriesOrder(int $companyId, array $base, Farm $farm, string $code, string $dteNumber): void
    {
        $batch = $this->externalBatch($companyId, (int) $farm->id, $this->batchName($code), 220, 180, 260, true);
        $order = EntryOrder::withoutGlobalScopes()->create([
            ...$base,
            ...$this->calves('MIXED', 15),
            'male_count' => 10,
            'female_count' => 5,
            'age_min_months' => 12,
            'age_max_months' => 15,
            'estimated_weight' => 220,
            'min_weight' => 180,
            'max_weight' => 260,
            'code' => $code,
            'number' => $this->nextNumber($companyId),
            'status' => 'IN_TRANSIT',
            'auction_number' => '338',
            'batch_id' => $batch->id,
            'batch_name' => $this->batchName($code),
            'batch_name_mode' => 'AUTO',
            'first_dte_at' => now(),
        ]);
        $this->categories($order, [['NOVILLITO', 6], ['TORITO', 4], ['VAQUILLONA', 5]]);
        $breedLine = $this->breeds($order, [['Angus', 'Negro']])[0];
        $this->history($order, null, 'AWAITING_DTE', 'Compra confirmada: en espera de DTE');
        $dte = $this->loadDte($order, $breedLine, (int) $batch->id, $farm, $dteNumber, '2026-10-02', 'M', 15);
        $this->history($order, 'AWAITING_DTE', 'IN_TRANSIT', null, ['dte_number' => $dteNumber, 'head_count' => 15, 'with_dte_total' => 15, 'pending_dte' => 0]);
        $this->receiptSheet($order, $dte, 'INDIVIDUAL', 'CODE');
    }

    /**
     * 8 Novillito + 4 Torito of two breeds, in transit with its R1 written in words: the sheet with
     * the RAZA, PELAJE and CATEGORÍA columns.
     *
     * @param array<string, mixed> $base
     */
    private function writtenReferencesOrder(int $companyId, array $base, Farm $farm, string $code, string $dteNumber): void
    {
        $batch = $this->externalBatch($companyId, (int) $farm->id, $this->batchName($code), 220, 180, 260, true);
        $order = EntryOrder::withoutGlobalScopes()->create([
            ...$base,
            ...$this->calves('MALE', 12),
            'age_min_months' => 12,
            'age_max_months' => 15,
            'estimated_weight' => 220,
            'min_weight' => 180,
            'max_weight' => 260,
            'code' => $code,
            'number' => $this->nextNumber($companyId),
            'status' => 'IN_TRANSIT',
            'auction_number' => '338',
            'batch_id' => $batch->id,
            'batch_name' => $this->batchName($code),
            'batch_name_mode' => 'AUTO',
            'first_dte_at' => now(),
        ]);
        $this->categories($order, [['NOVILLITO', 8], ['TORITO', 4]]);
        $breedLine = $this->breeds($order, [['Braford', 'Colorado'], ['Brangus', 'Negro']])[0];
        $this->history($order, null, 'AWAITING_DTE', 'Compra confirmada: en espera de DTE');
        $dte = $this->loadDte($order, $breedLine, (int) $batch->id, $farm, $dteNumber, '2026-10-02', 'M', 12);
        $this->history($order, 'AWAITING_DTE', 'IN_TRANSIT', null, ['dte_number' => $dteNumber, 'head_count' => 12, 'with_dte_total' => 12, 'pending_dte' => 0]);
        $this->receiptSheet($order, $dte, 'INDIVIDUAL', 'WRITTEN');
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
