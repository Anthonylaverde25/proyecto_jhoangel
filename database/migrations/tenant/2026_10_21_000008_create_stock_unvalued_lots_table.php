<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One per line of an unvalued delivery note (model 2.2): what arrived without price, how much of it
 * is still in the warehouse, and the cost its invoice brought.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_unvalued_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('delivery_note_line_id');          // fiscal_document_lines of the delivery note
            $table->unsignedBigInteger('receipt_line_id');                // item_document_lines of its DELIVERY_RECEIPT
            $table->decimal('quantity', 18, 4);                           // received, item unit
            $table->decimal('remaining_quantity', 18, 4);                 // still in the warehouse unvalued
            $table->string('status', 10)->default('PENDING');             // PENDING | VALUED | VOIDED
            $table->decimal('valued_unit_cost', 18, 6)->nullable();       // per item unit, from the invoice line
            $table->decimal('valued_quantity', 18, 4)->nullable();        // units it valued in stock (n of model 2.3)
            $table->unsignedBigInteger('valued_by_line_id')->nullable();  // fiscal_document_lines of the invoice
            $table->unsignedBigInteger('valuation_line_id')->nullable();  // item_document_lines of the VALUATION
            $table->timestamp('valued_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'company_id'], 'uq_sulot_id_company');
            $table->unique('delivery_note_line_id', 'uq_sulot_delivery_line');
            $table->index(['warehouse_id', 'item_id', 'status', 'id'], 'idx_sulot_fifo');

            $table->foreign(['warehouse_id', 'company_id'])           // stock_unvalued_lots_warehouse_id_company_id_foreign
                ->references(['id', 'company_id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['item_id', 'company_id'])                // stock_unvalued_lots_item_id_company_id_foreign
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign(['delivery_note_line_id', 'company_id'])  // stock_unvalued_lots_delivery_note_line_id_company_id_foreign
                ->references(['id', 'company_id'])->on('fiscal_document_lines')->restrictOnDelete();
            $table->foreign(['receipt_line_id', 'company_id'])        // stock_unvalued_lots_receipt_line_id_company_id_foreign
                ->references(['id', 'company_id'])->on('item_document_lines')->restrictOnDelete();
            $table->foreign(['valued_by_line_id', 'company_id'])      // stock_unvalued_lots_valued_by_line_id_company_id_foreign
                ->references(['id', 'company_id'])->on('fiscal_document_lines')->restrictOnDelete();
            $table->foreign(['valuation_line_id', 'company_id'])      // stock_unvalued_lots_valuation_line_id_company_id_foreign
                ->references(['id', 'company_id'])->on('item_document_lines')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            $checks = [
                'chk_sulot_status' => "status IN ('PENDING','VALUED','VOIDED')",
                'chk_sulot_quantities' => 'quantity > 0 AND remaining_quantity >= 0 AND remaining_quantity <= quantity',
                'chk_sulot_valued' => "(status = 'VALUED') = (valued_unit_cost IS NOT NULL AND valued_quantity IS NOT NULL
                    AND valued_by_line_id IS NOT NULL AND valued_at IS NOT NULL)",
                'chk_sulot_remaining' => "status = 'PENDING' OR remaining_quantity = 0",
            ];
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE stock_unvalued_lots ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_unvalued_lots');
    }
};
