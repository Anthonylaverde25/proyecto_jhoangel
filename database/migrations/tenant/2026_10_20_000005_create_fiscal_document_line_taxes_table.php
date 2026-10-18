<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Taxes charged on a line (DS16): VAT, fuel tax, internal taxes. Line <-> tax, many to many, at most
 * once per tax. is_recoverable is copied from the tax when registered, so a later catalog change
 * never rewrites the cost of past documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_document_line_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('fiscal_document_line_id');
            $table->foreignId('tax_id')->constrained('taxes')->restrictOnDelete();
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete(); // legal rates (VAT)
            $table->decimal('rate', 7, 4)->nullable();                // percentage applied; NULL for fixed amounts
            $table->decimal('taxable_base', 18, 4)->nullable();
            $table->decimal('amount', 18, 4);
            $table->boolean('is_recoverable');                        // snapshot of taxes.is_recoverable
            $table->timestamps();

            $table->unique(['fiscal_document_line_id', 'tax_id'], 'uq_fdlt_line_tax');
            $table->index(['company_id', 'tax_id'], 'idx_fdlt_company_tax');

            // Automatic name would be 69 characters (MySQL limit: 64).
            $table->foreign(['fiscal_document_line_id', 'company_id'], 'fdlt_line_company_foreign')
                ->references(['id', 'company_id'])->on('fiscal_document_lines')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE fiscal_document_line_taxes ADD CONSTRAINT chk_fdlt_values
                CHECK (amount >= 0 AND (taxable_base IS NULL OR taxable_base >= 0)
                    AND (rate IS NULL OR (rate >= 0 AND rate <= 100)))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_line_taxes');
    }
};
