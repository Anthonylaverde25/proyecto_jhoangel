<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Materialised balance and weighted average cost per (warehouse, item). Only the application of a
 * movement writes quantity, average_unit_cost and last_movement_id, in the same transaction and with
 * the row locked. critical_stock is the only user-editable column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('item_id');
            $table->decimal('quantity', 18, 4)->default(0);            // may go negative (D5)
            $table->decimal('average_unit_cost', 18, 6)->default(0);
            $table->decimal('critical_stock', 18, 4)->nullable();      // NULL = no control (D19)
            $table->unsignedBigInteger('last_movement_id')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'item_id'], 'uq_istock_warehouse_item');
            $table->index(['company_id', 'item_id'], 'idx_istock_company_item');

            $table->foreign(['warehouse_id', 'company_id'])          // item_stocks_warehouse_id_company_id_foreign
                ->references(['id', 'company_id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['item_id', 'company_id'])               // item_stocks_item_id_company_id_foreign
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign(['last_movement_id', 'company_id'])      // item_stocks_last_movement_id_company_id_foreign
                ->references(['id', 'company_id'])->on('stock_movements')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE item_stocks ADD CONSTRAINT chk_istock_values
                CHECK (average_unit_cost >= 0 AND (critical_stock IS NULL OR critical_stock >= 0))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_stocks');
    }
};
