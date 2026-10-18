<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Taxes charged on the whole document (DS16): VAT and IIBB perceptions. VAT per rate is not stored
 * here: it is summed from the line taxes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_document_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('fiscal_document_id');
            $table->foreignId('tax_id')->constrained('taxes')->restrictOnDelete();
            $table->decimal('rate', 7, 4)->nullable();                // as on paper, when shown
            $table->decimal('taxable_base', 18, 4)->nullable();
            $table->decimal('amount', 18, 4);
            $table->boolean('is_recoverable');                        // snapshot
            $table->timestamps();

            $table->unique(['fiscal_document_id', 'tax_id'], 'uq_fdtax_document_tax');

            $table->foreign(['fiscal_document_id', 'company_id'])     // fiscal_document_taxes_fiscal_document_id_company_id_foreign
                ->references(['id', 'company_id'])->on('fiscal_documents')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE fiscal_document_taxes ADD CONSTRAINT chk_fdtax_values
                CHECK (amount >= 0 AND (taxable_base IS NULL OR taxable_base >= 0)
                    AND (rate IS NULL OR (rate >= 0 AND rate <= 100)))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_taxes');
    }
};
