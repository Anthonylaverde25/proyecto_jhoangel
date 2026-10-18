<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed catalog of Argentine taxes found on purchase documents (DS15, DS16) and the legal rates the
 * system offers. applies_to says whether a tax is charged per line or on the whole document;
 * is_recoverable says whether it is a tax credit (never cost) or part of the cost (DS17).
 */
return new class extends Migration
{
    /** ISO 3166-2:AR jurisdictions, one IIBB perception each. */
    private const PROVINCES = [
        'AR-B' => 'Buenos Aires', 'AR-C' => 'CABA', 'AR-K' => 'Catamarca', 'AR-H' => 'Chaco',
        'AR-U' => 'Chubut', 'AR-X' => 'Córdoba', 'AR-W' => 'Corrientes', 'AR-E' => 'Entre Ríos',
        'AR-P' => 'Formosa', 'AR-Y' => 'Jujuy', 'AR-L' => 'La Pampa', 'AR-F' => 'La Rioja',
        'AR-M' => 'Mendoza', 'AR-N' => 'Misiones', 'AR-Q' => 'Neuquén', 'AR-R' => 'Río Negro',
        'AR-A' => 'Salta', 'AR-J' => 'San Juan', 'AR-D' => 'San Luis', 'AR-Z' => 'Santa Cruz',
        'AR-S' => 'Santa Fe', 'AR-G' => 'Santiago del Estero', 'AR-V' => 'Tierra del Fuego', 'AR-T' => 'Tucumán',
    ];

    /** code, name, rate, afip_code, order */
    private const VAT_RATES = [
        ['VAT_21', '21 %', '21.0000', '5', 1],
        ['VAT_10_5', '10,5 %', '10.5000', '4', 2],
        ['VAT_27', '27 %', '27.0000', '6', 3],
        ['VAT_5', '5 %', '5.0000', '8', 4],
        ['VAT_2_5', '2,5 %', '2.5000', '9', 5],
        ['VAT_0', '0 %', '0.0000', '3', 6],
        ['VAT_EXEMPT', 'Exento', '0.0000', '2', 7],
        ['VAT_NOT_TAXED', 'No gravado', '0.0000', '1', 8],
    ];

    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique('uq_taxes_code');
            $table->string('name', 100);
            $table->string('kind', 16);                        // VAT | PERCEPTION | FUEL | INTERNAL
            $table->string('jurisdiction', 8);                 // NATIONAL or ISO 3166-2:AR (AR-B, ...)
            $table->string('applies_to', 8);                   // LINE | DOCUMENT
            $table->boolean('is_recoverable');                 // tax credit, never cost
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('display_order')->default(1);
            $table->timestamps();
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_id')->constrained('taxes')->restrictOnDelete();
            $table->string('code', 32)->unique('uq_trate_code');
            $table->string('name', 32);                        // "21 %", "Exento"
            $table->decimal('rate', 7, 4);                     // percentage
            $table->string('afip_code', 3)->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();              // NULL = in force
            $table->unsignedSmallInteger('display_order')->default(1);
            $table->timestamps();

            $table->index(['tax_id', 'valid_from'], 'idx_trate_tax_from');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE taxes ADD CONSTRAINT chk_taxes_kind
                CHECK (kind IN ('VAT','PERCEPTION','FUEL','INTERNAL'))");
            DB::statement("ALTER TABLE taxes ADD CONSTRAINT chk_taxes_applies_to
                CHECK (applies_to IN ('LINE','DOCUMENT'))");
            DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT chk_trate_values
                CHECK (rate >= 0 AND rate <= 100 AND (valid_to IS NULL OR valid_to >= valid_from))');
        }

        $this->seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('taxes');
    }

    private function seed(): void
    {
        $now = now();
        $tax = fn (string $code, string $name, string $kind, string $jurisdiction, string $appliesTo, bool $recoverable, int $order): int =>
            DB::table('taxes')->insertGetId([
                'code' => $code, 'name' => $name, 'kind' => $kind, 'jurisdiction' => $jurisdiction,
                'applies_to' => $appliesTo, 'is_recoverable' => $recoverable, 'is_active' => true,
                'display_order' => $order, 'created_at' => $now, 'updated_at' => $now,
            ]);

        $vatId = $tax('VAT', 'IVA', 'VAT', 'NATIONAL', 'LINE', true, 1);
        $tax('FUEL_TAX', 'Impuesto a los combustibles (ITC)', 'FUEL', 'NATIONAL', 'LINE', false, 2);
        $tax('INTERNAL_TAX', 'Impuestos internos', 'INTERNAL', 'NATIONAL', 'LINE', false, 3);
        $tax('VAT_PERCEPTION', 'Percepción de IVA', 'PERCEPTION', 'NATIONAL', 'DOCUMENT', true, 4);

        $order = 5;
        foreach (self::PROVINCES as $iso => $province) {
            $code = 'IIBB_PERCEPTION_'.str_replace('-', '_', $iso);           // IIBB_PERCEPTION_AR_B
            $tax($code, "Percepción IIBB {$province}", 'PERCEPTION', $iso, 'DOCUMENT', true, $order++);
        }

        foreach (self::VAT_RATES as [$code, $name, $rate, $afip, $rateOrder]) {
            DB::table('tax_rates')->insert([
                'tax_id' => $vatId, 'code' => $code, 'name' => $name, 'rate' => $rate, 'afip_code' => $afip,
                'valid_from' => '2000-01-01', 'valid_to' => null, 'display_order' => $rateOrder,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
};
