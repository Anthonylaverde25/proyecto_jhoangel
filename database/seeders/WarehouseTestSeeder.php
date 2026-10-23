<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\DTOs\Stock\StockOperationDTO;
use App\Application\DTOs\Stock\StockTransferDTO;
use App\Application\UseCases\Stock\RegisterConsumptionUseCase;
use App\Application\UseCases\Stock\RegisterInitialStockUseCase;
use App\Application\UseCases\Stock\RegisterStockTransferUseCase;
use App\Application\UseCases\Warehouses\CreateWarehouseUseCase;
use App\Application\UseCases\Warehouses\SetWarehouseActiveUseCase;
use App\Core\Enums\WarehouseKind;
use App\Core\Exceptions\FieldValidationException;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Item;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * Warehouses and transfers that back the delivery D screens (plan part 7 §7.1), through the use cases.
 * Seeding again creates only what is missing: a warehouse by its code, an operation by its fixed
 * idempotency key. It never changes the default nor reactivates what the user deactivated. Runs after
 * DeliveryNoteTestSeeder, on the first company; the second one keeps a single warehouse.
 */
class WarehouseTestSeeder extends Seeder
{
    private int $companyId;

    public function run(): void
    {
        $company = Company::query()->orderBy('id')->first();
        if (!$company) {
            return;
        }
        $this->companyId = (int) $company->id;

        $main = Warehouse::withoutGlobalScopes()->where('company_id', $this->companyId)->where('kind', WarehouseKind::GENERAL->value)->where('is_default', true)->first();
        $salt = $this->item('Sal mineral');
        $ivermectin = $this->item('Ivermectina 1%');
        $heifers = Batch::withoutGlobalScopes()->where('company_id', $this->companyId)->where('name', 'Vaquillonas')->first();
        if (!$main || !$salt || !$ivermectin || !$heifers) {
            $this->command?->warn('WarehouseTestSeeder: falta lo que cargan los seeders de B2 y C. Se omite.');

            return;
        }

        $shed = $this->warehouse('ALM-02', 'Galpón de sanidad');
        // S-0: the first tank, which becomes the tanks' default.
        $this->warehouse('TER-01', 'Termo principal', kind: WarehouseKind::CRYO_TANK);
        $this->warehouse('ALM-03', 'Depósito viejo', $old);
        if ($old) {
            app(SetWarehouseActiveUseCase::class)($this->id('ALM-03'), false, null, $this->companyId);
        }

        // 01 · Sal mineral al galpón: sólo lo valorizado (los kg del remito seed-c-05 quedan en ALM-01)
        $this->attempt('seed-d-01', fn () => app(RegisterStockTransferUseCase::class)(StockTransferDTO::fromArray([
            'from_warehouse_id' => $main->id, 'to_warehouse_id' => $shed, 'occurred_at' => '2026-10-17', 'idempotency_key' => 'seed-d-01',
            'lines' => [['item_id' => $salt->id, 'input_quantity' => '60', 'input_unit_id' => $this->unitId('KG')]],
        ]), $this->companyId));

        // 02 · consumo desde el galpón: deja a seed-d-01 sin poder anularse (DD10)
        $this->attempt('seed-d-02', fn () => app(RegisterConsumptionUseCase::class)(StockOperationDTO::fromArray([
            'warehouse_id' => $shed, 'occurred_at' => '2026-10-18', 'idempotency_key' => 'seed-d-02', 'batch_id' => $heifers->id, 'heads_treated' => 40,
            'lines' => [['item_id' => $salt->id, 'input_quantity' => '20', 'input_unit_id' => $this->unitId('KG')]],
        ]), $this->companyId));

        // 03 · stock inicial con almacén elegido
        $this->attempt('seed-d-03', fn () => app(RegisterInitialStockUseCase::class)(StockOperationDTO::fromArray([
            'warehouse_id' => $shed, 'occurred_at' => '2026-10-16', 'idempotency_key' => 'seed-d-03',
            'lines' => [['item_id' => $ivermectin->id, 'input_quantity' => '500', 'input_unit_id' => $this->unitId('ML'), 'input_unit_price' => '25']],
        ]), $this->companyId));
    }

    /** The warehouse's id, created when its code is missing. */
    private function warehouse(string $code, string $name, ?bool &$created = null, WarehouseKind $kind = WarehouseKind::GENERAL): int
    {
        $created = false;
        $existing = $this->id($code);
        if ($existing !== null) {
            return $existing;
        }
        $created = true;

        return app(CreateWarehouseUseCase::class)($code, $name, $kind, $this->companyId)->id;
    }

    private function id(string $code): ?int
    {
        $id = Warehouse::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', $code)->value('id');

        return $id === null ? null : (int) $id;
    }

    /** A dev base the user already operated may refuse a load (an inactive warehouse, no valued stock): warn and go on. */
    private function attempt(string $key, callable $load): void
    {
        try {
            $load();
        } catch (FieldValidationException $e) {
            $this->command?->warn("WarehouseTestSeeder {$key}: {$e->getMessage()}");
        }
    }

    private function item(string $name): ?Item
    {
        return Item::withoutGlobalScopes()->where('company_id', $this->companyId)->where('name', $name)->first();
    }

    private function unitId(string $code): int
    {
        return (int) UnitOfMeasure::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', $code)->value('id');
    }
}
