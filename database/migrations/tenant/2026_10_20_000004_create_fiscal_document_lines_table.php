<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lines of a provider document, as on paper, converted to the item's unit. The line's cost is its
 * amount plus its non-recoverable taxes (DS5, DS17). Services and non-stock articles carry the batch
 * that received them (D22).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('fiscal_document_id');
            $table->unsignedSmallInteger('position');                 // order on the paper, from 1
            $table->unsignedBigInteger('item_id');
            $table->decimal('input_quantity', 18, 4);
            $table->unsignedBigInteger('input_unit_id')->nullable();  // exactly one of unit / presentation
            $table->unsignedBigInteger('input_presentation_id')->nullable();
            $table->decimal('quantity', 18, 4);                       // in the item's unit
            $table->decimal('unit_price', 18, 6);                     // per input unit, as on paper
            $table->decimal('line_amount', 18, 4);                    // input_quantity x unit_price
            $table->decimal('non_recoverable_tax_amount', 18, 4)->default(0);
            $table->decimal('cost_amount', 18, 4);                    // line_amount + non-recoverable taxes
            $table->decimal('unit_cost', 18, 6);                      // cost_amount / quantity (DS4)
            $table->unsignedBigInteger('batch_id')->nullable();       // services and non-stock articles only
            $table->unsignedInteger('heads_treated')->nullable();
            $table->timestamps();

            $table->unique(['id', 'company_id'], 'uq_fdl_id_company');
            $table->unique(['fiscal_document_id', 'position'], 'uq_fdl_document_position');
            $table->index(['company_id', 'item_id'], 'idx_fdl_company_item');     // last purchase, in-use guards
            $table->index(['company_id', 'batch_id'], 'idx_fdl_company_batch');   // batch history
            $table->index(['company_id', 'input_unit_id'], 'idx_fdl_company_unit');

            $table->foreign(['fiscal_document_id', 'company_id'])     // fiscal_document_lines_fiscal_document_id_company_id_foreign
                ->references(['id', 'company_id'])->on('fiscal_documents')->restrictOnDelete();
            $table->foreign(['item_id', 'company_id'])               // fiscal_document_lines_item_id_company_id_foreign
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign(['input_unit_id', 'company_id'])         // fiscal_document_lines_input_unit_id_company_id_foreign
                ->references(['id', 'company_id'])->on('units_of_measure')->restrictOnDelete();
            $table->foreign(['input_presentation_id', 'item_id'])    // fiscal_document_lines_input_presentation_id_item_id_foreign
                ->references(['id', 'item_id'])->on('item_presentations')->restrictOnDelete();
            $table->foreign(['batch_id', 'company_id'])              // fiscal_document_lines_batch_id_company_id_foreign
                ->references(['id', 'company_id'])->on('batches')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            $checks = [
                'chk_fdl_input_source' => '(input_unit_id IS NULL) <> (input_presentation_id IS NULL)',
                'chk_fdl_quantities' => 'input_quantity > 0 AND quantity > 0',
                'chk_fdl_amounts' => 'unit_price >= 0 AND line_amount >= 0 AND non_recoverable_tax_amount >= 0
                    AND cost_amount >= 0 AND unit_cost >= 0',
                'chk_fdl_heads' => '(batch_id IS NULL AND heads_treated IS NULL) OR (batch_id IS NOT NULL AND heads_treated > 0)',
                'chk_fdl_position' => 'position > 0',
            ];
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE fiscal_document_lines ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_lines');
    }
};
