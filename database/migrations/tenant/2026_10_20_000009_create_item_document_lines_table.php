<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock lines of an operation: always a stock-tracked article. A purchase or return line points to
 * the provider document line it comes from; an initial-stock line declares its price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('item_document_id');
            $table->unsignedSmallInteger('position');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('fiscal_document_line_id')->nullable(); // PURCHASE / RETURN_TO_PROVIDER lines
            $table->decimal('input_quantity', 18, 4);
            $table->unsignedBigInteger('input_unit_id')->nullable();
            $table->unsignedBigInteger('input_presentation_id')->nullable();
            $table->decimal('quantity', 18, 4);                       // in the item's unit; 0 only on a count without difference
            $table->decimal('input_unit_price', 18, 6)->nullable();   // INITIAL only, as declared
            $table->decimal('unit_cost', 18, 6);
            $table->decimal('total_cost', 18, 4);
            $table->decimal('counted_quantity', 18, 4)->nullable();   // COUNT_ADJUSTMENT only, item unit
            $table->unsignedBigInteger('reverses_line_id')->nullable();
            $table->timestamps();

            $table->unique(['id', 'company_id'], 'uq_idl_id_company');
            $table->unique(['item_document_id', 'position'], 'uq_idl_document_position');
            $table->unique('reverses_line_id', 'uq_idl_reverses');
            $table->index(['company_id', 'item_id'], 'idx_idl_company_item');
            $table->index(['company_id', 'input_unit_id'], 'idx_idl_company_unit');
            $table->index(['company_id', 'fiscal_document_line_id'], 'idx_idl_company_fiscal_line');

            $table->foreign(['item_document_id', 'company_id'])      // item_document_lines_item_document_id_company_id_foreign
                ->references(['id', 'company_id'])->on('item_documents')->restrictOnDelete();
            $table->foreign(['item_id', 'company_id'])               // item_document_lines_item_id_company_id_foreign
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign(['fiscal_document_line_id', 'company_id']) // item_document_lines_fiscal_document_line_id_company_id_foreign
                ->references(['id', 'company_id'])->on('fiscal_document_lines')->restrictOnDelete();
            $table->foreign(['input_unit_id', 'company_id'])         // item_document_lines_input_unit_id_company_id_foreign
                ->references(['id', 'company_id'])->on('units_of_measure')->restrictOnDelete();
            $table->foreign(['input_presentation_id', 'item_id'])    // item_document_lines_input_presentation_id_item_id_foreign
                ->references(['id', 'item_id'])->on('item_presentations')->restrictOnDelete();
        });

        Schema::table('item_document_lines', function (Blueprint $table) {
            $table->foreign(['reverses_line_id', 'company_id'])      // item_document_lines_reverses_line_id_company_id_foreign
                ->references(['id', 'company_id'])->on('item_document_lines')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            $checks = [
                'chk_idl_input_source' => '(input_unit_id IS NULL) <> (input_presentation_id IS NULL)',
                'chk_idl_quantities' => 'input_quantity > 0 AND quantity >= 0
                    AND (counted_quantity IS NULL OR counted_quantity >= 0)',
                'chk_idl_costs' => 'unit_cost >= 0 AND total_cost >= 0 AND (input_unit_price IS NULL OR input_unit_price >= 0)',
                'chk_idl_position' => 'position > 0',
            ];
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE item_document_lines ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_document_lines');
    }
};
