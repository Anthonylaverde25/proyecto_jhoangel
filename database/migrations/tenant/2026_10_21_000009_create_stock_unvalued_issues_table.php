<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Units an outbound line took from a lot before its invoice (model 2.4). Their cost is NULL until the
 * lot is valued; a reversal of the outbound line returns them and stamps reversed_by_line_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_unvalued_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('lot_id');
            $table->unsignedBigInteger('item_document_line_id');      // the outbound line (consumption, count −, return)
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 6)->nullable();          // the lot's valued_unit_cost, once valued
            $table->decimal('total_cost', 18, 4)->nullable();
            $table->unsignedBigInteger('reversed_by_line_id')->nullable();
            $table->timestamps();

            $table->unique(['item_document_line_id', 'lot_id'], 'uq_suiss_line_lot');
            $table->index(['company_id', 'lot_id'], 'idx_suiss_company_lot');

            $table->foreign(['lot_id', 'company_id'])                 // stock_unvalued_issues_lot_id_company_id_foreign
                ->references(['id', 'company_id'])->on('stock_unvalued_lots')->restrictOnDelete();
            $table->foreign(['item_document_line_id', 'company_id'])  // stock_unvalued_issues_item_document_line_id_company_id_foreign
                ->references(['id', 'company_id'])->on('item_document_lines')->restrictOnDelete();
            $table->foreign(['reversed_by_line_id', 'company_id'])    // stock_unvalued_issues_reversed_by_line_id_company_id_foreign
                ->references(['id', 'company_id'])->on('item_document_lines')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            $checks = [
                'chk_suiss_quantity' => 'quantity > 0',
                'chk_suiss_cost' => '(unit_cost IS NULL) = (total_cost IS NULL) AND (unit_cost IS NULL OR (unit_cost >= 0 AND total_cost >= 0))',
            ];
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE stock_unvalued_issues ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_unvalued_issues');
    }
};
