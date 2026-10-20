<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\DTOs\Stock\FiscalDocumentDTO;
use App\Application\DTOs\Stock\StockOperationDTO;
use App\Application\UseCases\FiscalDocuments\RegisterFiscalDocumentUseCase;
use App\Application\UseCases\Stock\RegisterConsumptionUseCase;
use App\Models\Batch;
use App\Models\Company;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentType;
use App\Models\Item;
use App\Models\ItemPresentation;
use App\Models\Provider;
use App\Models\Tax;
use App\Models\TaxRate;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Seeder;

/**
 * Delivery notes and their conversion to invoices that back the delivery C screens (plan part 8 §8.1),
 * loaded through the use cases. Every load carries a fixed idempotency key: seeding again finds it and
 * duplicates nothing. Runs after StockLedgerTestSeeder, on the first company, and needs its catalog.
 */
class DeliveryNoteTestSeeder extends Seeder
{
    private const EL_TALA_CUIT = '30708451238';
    private const SOUTH_CUIT = '30987654321';
    private const FUEL_CUIT = '30715554441';

    private int $companyId;

    public function run(): void
    {
        $company = Company::query()->orderBy('id')->first();
        if (!$company) {
            return;
        }
        $this->companyId = (int) $company->id;

        $elTala = $this->provider(self::EL_TALA_CUIT);
        $south = $this->provider(self::SOUTH_CUIT);
        $fuel = $this->provider(self::FUEL_CUIT);
        $ivermectin = $this->item('Ivermectina 1%');
        $salt = $this->item('Sal mineral');
        $visit = $this->item('Visita veterinaria');
        $gasoil = Item::withoutGlobalScopes()->where('company_id', $this->companyId)->where('code', 'GASOIL')->first();
        $heifers = Batch::withoutGlobalScopes()->where('company_id', $this->companyId)->where('name', 'Vaquillonas')->first();
        if (!$elTala || !$south || !$fuel || !$ivermectin || !$salt || !$visit || !$gasoil || !$heifers) {
            $this->command?->warn('DeliveryNoteTestSeeder: falta lo que carga StockLedgerTestSeeder. Se omite.');

            return;
        }

        // 01 · remito sin precio de El Tala: dos partidas pendientes de valorizar
        $unpriced = $this->document('seed-c-01', $elTala, 'DELIVERY_NOTE', 1, 200, '2026-10-10', [
            $this->line($ivermectin, '10', null, presentation: 'Frasco × 500 ml'),
            $this->line($salt, '2', null, presentation: 'Bolsa × 25 kg'),
        ], valued: false);

        // 02 · consumo de Vaquillonas que toma lo valorizado y después la partida: costo pendiente en el lote
        app(RegisterConsumptionUseCase::class)(StockOperationDTO::fromArray([
            'occurred_at' => '2026-10-11', 'idempotency_key' => 'seed-c-02', 'batch_id' => $heifers->id, 'heads_treated' => 80,
            'lines' => [['item_id' => $ivermectin->id, 'input_quantity' => '4000', 'input_unit_id' => $this->unitId('ML')]],
        ]), $this->companyId);

        // 03 · remito valorizado de El Tala
        $priced = $this->document('seed-c-03', $elTala, 'DELIVERY_NOTE', 1, 205, '2026-10-12', [
            $this->line($ivermectin, '4', '12000', presentation: 'Frasco × 500 ml'),
        ], valued: true);

        // 04 · Factura A desde los dos remitos: valoriza la partida, sal quitada, precio distinto y una visita libre
        $this->document('seed-c-04', $elTala, 'INVOICE_A', 1, 460, '2026-10-16', [
            $this->line($ivermectin, '10', '12500', presentation: 'Frasco × 500 ml', vat: 'VAT_21', from: [$unpriced, 1]),
            $this->line($ivermectin, '4', '12500', presentation: 'Frasco × 500 ml', vat: 'VAT_21', from: [$priced, 1]),
            $this->line($visit, '1', '60000', unit: 'SERVICE', vat: 'VAT_21', batch: $heifers, heads: 80),
        ], notes: [$unpriced, $priced]);

        // 05 · remito sin precio de Ganadera del Sur que queda en la bandeja
        $this->document('seed-c-05', $south, 'DELIVERY_NOTE', 3, 45, '2026-10-02', [
            $this->line($salt, '25', null, unit: 'KG'),
        ], valued: false);

        // 06 · remito valorizado de gasoil y su factura con ITC: valorización por impuestos (Q3), sin novedades
        $oil = $this->document('seed-c-06', $fuel, 'DELIVERY_NOTE', 4, 310, '2026-10-13', [
            $this->line($gasoil, '500', '900', unit: 'L'),
        ], valued: true);
        $this->document('seed-c-07', $fuel, 'INVOICE_A', 4, 1100, '2026-10-15', [
            $this->line($gasoil, '500', '900', unit: 'L', vat: 'VAT_21', from: [$oil, 1], extraTaxes: [['tax_id' => $this->taxId('FUEL_TAX'), 'amount' => '47500']]),
        ], notes: [$oil]);
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param list<int|null> $notes delivery notes an invoice is converted from
     */
    private function document(string $key, Provider $provider, string $type, int $pointOfSale, int $number, string $date, array $lines, ?bool $valued = null, array $notes = []): ?int
    {
        $registration = app(RegisterFiscalDocumentUseCase::class)(FiscalDocumentDTO::fromArray([
            'provider_id' => $provider->id,
            'fiscal_document_type_id' => FiscalDocumentType::query()->where('code', $type)->value('id'),
            'point_of_sale' => $pointOfSale,
            'number' => $number,
            'issued_at' => $date,
            'is_valued' => $valued,
            'delivery_note_ids' => array_values(array_filter($notes)),
            'idempotency_key' => $key,
            'lines' => $lines,
        ]), $this->companyId);

        return $registration->document->getId();
    }

    /**
     * @param array{0: int|null, 1: int}|null $from delivery note and line position it is converted from
     * @param list<array<string, mixed>> $extraTaxes
     * @return array<string, mixed>
     */
    private function line(
        Item $item,
        string $quantity,
        ?string $price,
        ?string $unit = null,
        ?string $presentation = null,
        ?string $vat = null,
        ?Batch $batch = null,
        ?int $heads = null,
        ?array $from = null,
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
            'delivery_note_line_id' => $from === null || $from[0] === null ? null
                : FiscalDocumentLine::withoutGlobalScopes()->where('fiscal_document_id', $from[0])->where('position', $from[1])->value('id'),
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
}
