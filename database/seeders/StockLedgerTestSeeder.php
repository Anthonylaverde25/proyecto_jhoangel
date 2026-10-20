<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\DTOs\Stock\FiscalDocumentDTO;
use App\Application\DTOs\Stock\StockCountDTO;
use App\Application\DTOs\Stock\StockOperationDTO;
use App\Application\UseCases\FiscalDocuments\RegisterFiscalDocumentUseCase;
use App\Application\UseCases\Stock\RegisterConsumptionUseCase;
use App\Application\UseCases\Stock\RegisterInitialStockUseCase;
use App\Application\UseCases\Stock\RegisterStockCountUseCase;
use App\Application\UseCases\Stock\ReverseItemDocumentUseCase;
use App\Application\UseCases\Stock\SetCriticalStockUseCase;
use App\Core\Interfaces\IItemDocumentRepository;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentType;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemPresentation;
use App\Models\Provider;
use App\Models\Tax;
use App\Models\TaxRate;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Provider documents and stock operations that back the B2 screens (plan, Part 9.1), loaded through
 * the use cases so the ledger, the balances and the notices stay consistent. Every load carries a
 * fixed idempotency key: seeding again finds it and duplicates nothing.
 *
 * Expected balances: Sal mineral 150 kg · Ivermectina 1% 3.800 ml · Vacuna aftosa −25 dosis ·
 * Gasoil 1.000 l. Runs after ItemCatalogTestSeeder, on the first company.
 */
class StockLedgerTestSeeder extends Seeder
{
    private const EL_TALA_CUIT = '30708451238';
    private const MIXED_PROVIDER_CUIT = '30987654321';
    private const FUEL_PROVIDER_CUIT = '30715554441';

    private int $companyId;

    public function run(): void
    {
        $company = Company::query()->orderBy('id')->first();
        if (!$company) {
            $this->command?->warn('StockLedgerTestSeeder: no hay compañía cargada. Se omite.');

            return;
        }
        $this->companyId = (int) $company->id;

        $elTala = $this->provider(self::EL_TALA_CUIT);
        $mixed = $this->provider(self::MIXED_PROVIDER_CUIT);
        $ivermectin = $this->item('Ivermectina 1%');
        $salt = $this->item('Sal mineral');
        $fmd = $this->item('Vacuna aftosa');
        $wire = $this->item('Alambre liso 17/15');
        $visit = $this->item('Visita veterinaria');
        if (!$elTala || !$mixed || !$ivermectin || !$salt || !$fmd || !$wire || !$visit) {
            $this->command?->warn('StockLedgerTestSeeder: falta el catálogo de ItemCatalogTestSeeder. Se omite.');

            return;
        }
        $fuelProvider = $this->fuelProvider();
        $gasoil = $this->gasoil();
        $heifers = $this->heifers();

        // 01 · stock inicial
        $this->initial('seed-b2-01', '2026-10-01', [$this->stockLine($salt, '200', 'KG', '280')]);

        // 02 · Factura A mixta: Ivermectina al almacén, la visita al lote (DS14)
        $invoice = $this->fiscal('seed-b2-02', $elTala, 'INVOICE_A', 1, 123, '2026-10-01', [
            $this->fiscalLine($ivermectin, '10', '12000', presentation: 'Frasco × 500 ml', vat: 'VAT_21'),
            $this->fiscalLine($visit, '1', '60000', unit: 'SERVICE', vat: 'VAT_21', batch: $heifers, heads: 80),
        ], declared: '217800');

        // 03 · Factura A al 10,5 %: el promedio de la sal pasa a 290,36
        $this->fiscal('seed-b2-03', $mixed, 'INVOICE_A', 3, 77, '2026-10-03', [
            $this->fiscalLine($salt, '4', '7777', presentation: 'Bolsa × 25 kg', vat: 'VAT_10_5'),
        ]);

        // 04 y 05 · consumos de Vaquillonas con cabezas distintas
        $this->consumption('seed-b2-04', '2026-10-02', [$this->stockLine($ivermectin, '600', 'ML')], $heifers, 80);
        $this->consumption('seed-b2-05', '2026-10-09', [$this->stockLine($salt, '150', 'KG')], $heifers, 100);

        // 06 · Factura B: sin impuestos discriminados; alambre sin stock al lote
        $this->fiscal('seed-b2-06', $elTala, 'INVOICE_B', 1, 140, '2026-10-12', [
            $this->fiscalLine($wire, '2', '19000', unit: 'UNIT', batch: $heifers, heads: 100),
        ]);

        // 07 · consumo sin stock previo: saldo negativo
        $this->consumption('seed-b2-07', '2026-10-13', [$this->stockLine($fmd, '25', 'DOSE')]);

        // 08 y 09 · consumo anulado (DS12)
        $voided = $this->consumption('seed-b2-08', '2026-10-14', [$this->stockLine($salt, '50', 'KG')], $heifers, 100);
        if ($voided !== null) {
            app(ReverseItemDocumentUseCase::class)($voided, 'Era el lote de al lado', 'seed-b2-09', $this->companyId, null);
        }

        // 10 · conteo con diferencia
        app(RegisterStockCountUseCase::class)(new StockCountDTO(
            (int) $ivermectin->id, null, '4400', '4300', $this->unitId('ML'), null, 'Frasco roto', '2026-10-15', 'seed-b2-10'
        ), $this->companyId);

        // 11 · Factura A de gasoil: IVA + ITC en el renglón, percepción IIBB en el comprobante
        $this->fiscal('seed-b2-11', $fuelProvider, 'INVOICE_A', 4, 1050, '2026-10-05', [
            $this->fiscalLine($gasoil, '1000', '900', unit: 'L', vat: 'VAT_21', extraTaxes: [['tax_id' => $this->taxId('FUEL_TAX'), 'amount' => '95000']]),
        ], declared: '1211000', documentTaxes: [
            ['tax_id' => $this->taxId('IIBB_PERCEPTION_AR_B'), 'rate' => '3', 'taxable_base' => '900000', 'amount' => '27000'],
        ]);

        // 12 · Nota de crédito A que acredita la factura 0001-00000123: devolución al promedio
        $this->fiscal('seed-b2-12', $elTala, 'CREDIT_NOTE_A', 1, 31, '2026-10-16', [
            $this->fiscalLine($ivermectin, '1', '12000', presentation: 'Frasco × 500 ml', vat: 'VAT_21'),
        ], related: $invoice);

        // Nivel crítico de la sal: queda en 150 kg, por debajo de 200 kg
        $warehouse = (int) Warehouse::withoutGlobalScopes()->where('company_id', $this->companyId)->where('is_default', true)->value('id');
        app(SetCriticalStockUseCase::class)((int) $salt->id, $warehouse, '200', $this->companyId, null);
    }

    /** @param list<array<string, mixed>> $lines */
    private function initial(string $key, string $date, array $lines): void
    {
        app(RegisterInitialStockUseCase::class)(StockOperationDTO::fromArray([
            'occurred_at' => $date, 'idempotency_key' => $key, 'lines' => $lines,
        ]), $this->companyId);
    }

    /** @param list<array<string, mixed>> $lines */
    private function consumption(string $key, string $date, array $lines, ?Batch $batch = null, ?int $heads = null): ?int
    {
        $result = app(RegisterConsumptionUseCase::class)(StockOperationDTO::fromArray([
            'occurred_at' => $date, 'idempotency_key' => $key, 'lines' => $lines,
            'batch_id' => $batch?->id, 'heads_treated' => $heads,
        ]), $this->companyId);

        return $result->document->getId();
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param list<array<string, mixed>> $documentTaxes
     */
    private function fiscal(string $key, Provider $provider, string $type, int $pointOfSale, int $number, string $date, array $lines, ?string $declared = null, array $documentTaxes = [], ?int $related = null): ?int
    {
        $registration = app(RegisterFiscalDocumentUseCase::class)(FiscalDocumentDTO::fromArray([
            'provider_id' => $provider->id,
            'fiscal_document_type_id' => FiscalDocumentType::query()->where('code', $type)->value('id'),
            'point_of_sale' => $pointOfSale,
            'number' => $number,
            'issued_at' => $date,
            'declared_total' => $declared,
            'related_fiscal_document_id' => $related,
            'idempotency_key' => $key,
            'lines' => $lines,
            'document_taxes' => $documentTaxes,
        ]), $this->companyId);

        return $registration->document->getId();
    }

    /** @return array<string, mixed> */
    private function stockLine(Item $item, string $quantity, string $unit, ?string $price = null): array
    {
        return ['item_id' => $item->id, 'input_quantity' => $quantity, 'input_unit_id' => $this->unitId($unit), 'input_unit_price' => $price];
    }

    /**
     * @param list<array<string, mixed>> $extraTaxes
     * @return array<string, mixed>
     */
    private function fiscalLine(
        Item $item,
        string $quantity,
        string $price,
        ?string $unit = null,
        ?string $presentation = null,
        ?string $vat = null,
        ?Batch $batch = null,
        ?int $heads = null,
        array $extraTaxes = [],
    ): array {
        $taxes = $vat === null ? [] : [['tax_id' => $this->taxId('VAT'), 'tax_rate_id' => TaxRate::query()->where('code', $vat)->value('id')]];

        return [
            'item_id' => $item->id,
            'input_quantity' => $quantity,
            'input_unit_id' => $unit === null ? null : $this->unitId($unit),
            'input_presentation_id' => $presentation === null ? null
                : ItemPresentation::query()->where('item_id', $item->id)->where('name', $presentation)->value('id'),
            'unit_price' => $price,
            'batch_id' => $batch?->id,
            'heads_treated' => $heads,
            'taxes' => array_merge($taxes, $extraTaxes),
        ];
    }

    private function provider(string $cuit): ?Provider
    {
        return Provider::withoutGlobalScopes()->where('company_id', $this->companyId)->where('cuit', $cuit)->first();
    }

    private function item(string $name): ?Item
    {
        return Item::withoutGlobalScopes()->where('company_id', $this->companyId)->where('name', $name)->first();
    }

    private function unitId(string $code): int
    {
        return (int) UnitOfMeasure::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', $code)->value('id');
    }

    private function taxId(string $code): int
    {
        return (int) Tax::query()->where('code', $code)->value('id');
    }

    private function fuelProvider(): Provider
    {
        return Provider::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'cuit' => self::FUEL_PROVIDER_CUIT],
            ['name' => 'Estación de Servicio La Ruta', 'commercial_name' => 'La Ruta', 'location' => 'Ruta 226 km 160, Tandil',
                'is_active' => true, 'supplies_livestock' => false, 'supplies_items' => true]
        );
    }

    private function gasoil(): Item
    {
        $fuel = ItemCategory::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', 'FUEL')->value('id');

        return Item::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'code' => 'GASOIL'],
            ['name' => 'Gasoil', 'type' => 'ARTICLE', 'track_stock' => true, 'unit_of_measure_id' => $this->unitId('L'),
                'item_category_id' => $fuel, 'is_active' => true]
        );
    }

    /** The batch of the examples; created empty when missing (its heads are declared per operation). */
    private function heifers(): Batch
    {
        $existing = Batch::withoutGlobalScopes()->where('company_id', $this->companyId)->where('name', 'Vaquillonas')->first();
        if ($existing) {
            return $existing;
        }
        $id = DB::table('batches')->insertGetId([
            'company_id' => $this->companyId,
            'name' => 'Vaquillonas',
            'farm_id' => null,
            'is_active' => true,
            'is_system' => false,
            'knows_to_eat' => false,
            'activity_id' => DB::table('activities')->where('code', 'RECRIA')->value('id'),
            'batch_type_id' => BatchType::query()->where('code', 'OPERATIONAL')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Batch::withoutGlobalScopes()->findOrFail($id);
    }
}
