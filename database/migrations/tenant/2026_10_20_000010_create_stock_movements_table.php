<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Effect of an operation line on a warehouse. At most one movement per line; the id sequence defines
 * the kardex order (D4), not occurred_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('item_document_line_id');
            $table->unsignedBigInteger('item_id');                   // denormalised for the kardex index
            $table->tinyInteger('direction');                         // +1 inbound, -1 outbound
            $table->decimal('quantity', 18, 4);                       // always positive, item unit
            $table->decimal('unit_cost', 18, 6);
            $table->decimal('total_cost', 18, 4);
            $table->decimal('balance_after', 18, 4);                  // may be negative (D5)
            $table->decimal('average_cost_after', 18, 6);
            $table->timestamps();

            $table->unique('item_document_line_id', 'uq_smov_line');
            $table->unique(['id', 'company_id'], 'uq_smov_id_company');
            $table->index(['warehouse_id', 'item_id', 'id'], 'idx_smov_kardex');

            $table->foreign(['warehouse_id', 'company_id'])          // stock_movements_warehouse_id_company_id_foreign
                ->references(['id', 'company_id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['item_document_line_id', 'company_id']) // stock_movements_item_document_line_id_company_id_foreign
                ->references(['id', 'company_id'])->on('item_document_lines')->restrictOnDelete();
            $table->foreign(['item_id', 'company_id'])               // stock_movements_item_id_company_id_foreign
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            $checks = [
                'chk_smov_direction' => 'direction IN (-1, 1)',
                'chk_smov_quantity' => 'quantity > 0',
                'chk_smov_costs' => 'unit_cost >= 0 AND total_cost >= 0 AND average_cost_after >= 0',
            ];
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
