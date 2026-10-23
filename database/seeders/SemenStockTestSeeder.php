<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\DTOs\Semen\SemenLotShareDTO;
use App\Application\DTOs\Semen\SemenReceiptDTO;
use App\Application\DTOs\Semen\SemenSireDTO;
use App\Application\DTOs\Stock\StockCountDTO;
use App\Application\DTOs\Stock\StockOperationDTO;
use App\Application\DTOs\Stock\StockTransferDTO;
use App\Application\UseCases\Semen\CreateSemenSireUseCase;
use App\Application\UseCases\Semen\IdentifySemenLotUseCase;
use App\Application\UseCases\Semen\RegisterSemenReceiptUseCase;
use App\Application\UseCases\Stock\RegisterConsumptionUseCase;
use App\Application\UseCases\Stock\RegisterStockCountUseCase;
use App\Application\UseCases\Stock\RegisterStockTransferUseCase;
use App\Application\UseCases\Warehouses\CreateWarehouseUseCase;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\WarehouseKind;
use App\Core\Exceptions\FieldValidationException;
use App\Models\Breed;
use App\Models\Caravan;
use App\Models\Company;
use App\Models\Provider;
use App\Models\SemenSire;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Semen stock scenario (semen S-A, plan part 7 §7.1), first company, fake data. Creates only what is
 * missing and loads through the use cases with seed-sa-NN keys, so a dev base already operated is not
 * overwritten. Two tanks, three sires (one own bull), two lots of one semen across both tanks, a
 * discard, a count per lot and an entry without lot partly identified.
 */
class SemenStockTestSeeder extends Seeder
{
    private const PROVIDER_CUIT = '30718000013';

    private int $companyId;

    public function run(): void
    {
        $company = Company::query()->orderBy('id')->first();
        $main = $company === null ? null : $this->warehouseId((int) $company->id, 'TER-01');
        if ($company === null || $main === null) {
            $this->command?->warn('SemenStockTestSeeder: falta TER-01 (WarehouseTestSeeder). Se omite.');

            return;
        }
        $this->companyId = (int) $company->id;

        $field = $this->warehouseId($this->companyId, 'TER-02')
            ?? app(CreateWarehouseUseCase::class)('TER-02', 'Termo de campo', WarehouseKind::CRYO_TANK, $this->companyId)->id;
        $provider = Provider::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'cuit' => self::PROVIDER_CUIT],
            ['name' => 'Centro de IA (prueba)', 'supplies_livestock' => false, 'supplies_items' => true, 'is_active' => true],
        );

        $rambo = $this->sire('HBA-1001', 'Rambo', 'Angus');
        $cirilo = $this->sire('HBA-2002', 'Don Cirilo', 'Hereford');
        $own = $this->ownBull();

        $this->attempt('seed-sa-01', fn () => $this->receipt('seed-sa-01', $main, (int) $provider->id, '2026-10-01', [
            [$rambo, 'CONVENTIONAL', 'L-2301', 40, '18000'],
            [$rambo, 'SEXED_FEMALE', 'SX-77', 20, '45000'],
        ]));
        $this->attempt('seed-sa-02', fn () => $this->receipt('seed-sa-02', $main, (int) $provider->id, '2026-10-08', [[$rambo, 'CONVENTIONAL', 'L-2405', 60, '20000']]));
        if ($own !== null) {
            $this->attempt('seed-sa-03', fn () => $this->receipt('seed-sa-03', $field, null, '2026-10-09', [[$own, 'CONVENTIONAL', 'P-001', 15, '0']]));
        }

        $conventional = (int) DB::table('item_semen_details')->where('company_id', $this->companyId)->where('semen_sire_id', $rambo)->where('semen_type', 'CONVENTIONAL')->value('item_id');
        $l2301 = $this->lot($conventional, 'L-2301');
        $l2405 = $this->lot($conventional, 'L-2405');
        $straw = (int) UnitOfMeasure::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', 'STRAW')->value('id');

        $this->attempt('seed-sa-04', fn () => app(RegisterStockTransferUseCase::class)(StockTransferDTO::fromArray([
            'from_warehouse_id' => $main, 'to_warehouse_id' => $field, 'occurred_at' => '2026-10-10', 'idempotency_key' => 'seed-sa-04',
            'lines' => [['item_id' => $conventional, 'input_quantity' => '10', 'input_unit_id' => $straw, 'semen_lot_id' => $l2301]],
        ]), $this->companyId));
        $this->attempt('seed-sa-05', fn () => app(RegisterConsumptionUseCase::class)(StockOperationDTO::fromArray([
            'warehouse_id' => $main, 'occurred_at' => '2026-10-12', 'idempotency_key' => 'seed-sa-05', 'notes' => 'Pajuelas descongeladas por error',
            'lines' => [['item_id' => $conventional, 'input_quantity' => '2', 'input_unit_id' => $straw, 'semen_lot_id' => $l2405]],
        ]), $this->companyId));
        $this->attempt('seed-sa-06', fn () => app(RegisterStockCountUseCase::class)(StockCountDTO::fromArray([
            'item_id' => $conventional, 'warehouse_id' => $field, 'semen_lot_id' => $l2301, 'system_quantity_seen' => '10', 'counted_quantity' => '9',
            'input_unit_id' => $straw, 'reason' => 'Falta una pajuela en la canastilla', 'occurred_at' => '2026-10-14', 'idempotency_key' => 'seed-sa-06',
        ]), $this->companyId));

        $this->attempt('seed-sa-07', fn () => $this->receipt('seed-sa-07', $main, (int) $provider->id, '2026-10-15', [[$cirilo, 'CONVENTIONAL', null, 25, '15000']]));
        $this->identifyOnce('seed-sa-07', 'DC-10', '15');
    }

    /** @param list<array{0: int, 1: string, 2: ?string, 3: int, 4: string}> $lines */
    private function receipt(string $key, int $warehouseId, ?int $providerId, string $date, array $lines): void
    {
        app(RegisterSemenReceiptUseCase::class)(SemenReceiptDTO::fromArray([
            'occurred_at' => $date, 'warehouse_id' => $warehouseId, 'provider_id' => $providerId, 'idempotency_key' => $key,
            'lines' => array_map(fn (array $l) => ['semen_sire_id' => $l[0], 'semen_type' => $l[1], 'lot_code' => $l[2], 'quantity' => (string) $l[3], 'unit_cost' => $l[4]], $lines),
        ]), $this->companyId);
    }

    /** The entry's straws without lot get a first part identified, only once. */
    private function identifyOnce(string $key, string $lotCode, string $quantity): void
    {
        $entry = DB::table('semen_unidentified_entries as e')
            ->join('item_document_lines as l', 'l.id', '=', 'e.item_document_line_id')
            ->join('item_documents as d', 'd.id', '=', 'l.item_document_id')
            ->where('d.company_id', $this->companyId)->where('d.idempotency_key', $key)
            ->first(['e.id', 'e.identified_quantity']);
        if ($entry === null || bccomp((string) $entry->identified_quantity, '0', 4) !== 0) {
            return;
        }
        $this->attempt("{$key}-identify", fn () => app(IdentifySemenLotUseCase::class)((int) $entry->id, [new SemenLotShareDTO(null, $lotCode, null, $quantity)], $this->companyId, null));
    }

    private function sire(string $registry, string $name, string $breed): int
    {
        $existing = SemenSire::withoutGlobalScopes()->where('company_id', $this->companyId)->where('registry_code', $registry)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }
        $breedId = Breed::query()->where('name', $breed)->value('id');

        return (int) app(CreateSemenSireUseCase::class)(new SemenSireDTO($name, $registry, $breedId === null ? null : (int) $breedId, 'Centro de IA (prueba)', null, null), $this->companyId)->id;
    }

    /** The first male of the company as an own bull (DA6), unless one already is. */
    private function ownBull(): ?int
    {
        $existing = SemenSire::withoutGlobalScopes()->where('company_id', $this->companyId)->whereNotNull('caravan_id')->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }
        $bull = Caravan::withoutGlobalScopes()->where('company_id', $this->companyId)->where('sex', AnimalSex::MALE->value)->orderBy('id')->value('id');

        return $bull === null ? null : (int) app(CreateSemenSireUseCase::class)(new SemenSireDTO(null, null, null, null, (int) $bull, 'Toro propio con semen extraído.'), $this->companyId)->id;
    }

    private function lot(int $itemId, string $code): ?int
    {
        $id = DB::table('semen_lots')->where('item_id', $itemId)->where('code', $code)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function warehouseId(int $companyId, string $code): ?int
    {
        $id = Warehouse::withoutGlobalScopes()->where('company_id', $companyId)->where('code', $code)->value('id');

        return $id === null ? null : (int) $id;
    }

    /** A dev base the user already operated may refuse a load: warn and go on. */
    private function attempt(string $key, callable $load): void
    {
        try {
            $load();
        } catch (FieldValidationException $e) {
            $this->command?->warn("SemenStockTestSeeder {$key}: {$e->getMessage()}");
        }
    }
}
