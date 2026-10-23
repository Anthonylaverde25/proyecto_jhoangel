<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Straws of a lot in one tank. Updated by the StockLedger in the same transaction as item_stocks, so
 * that per tank the lots of an item add up to the item's balance (SL3). Never below zero (SL4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semen_lot_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('semen_lot_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->decimal('quantity', 18, 4)->default(0);            // straws
            $table->timestamps();

            $table->unique(['semen_lot_id', 'warehouse_id'], 'uq_slstock_lot_warehouse');
            $table->index(['company_id', 'warehouse_id'], 'idx_slstock_company_warehouse');
            $table->foreign(['semen_lot_id', 'company_id'])          // semen_lot_stocks_semen_lot_id_company_id_foreign
                ->references(['id', 'company_id'])->on('semen_lots')->restrictOnDelete();
            $table->foreign(['warehouse_id', 'company_id'])          // semen_lot_stocks_warehouse_id_company_id_foreign
                ->references(['id', 'company_id'])->on('warehouses')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE semen_lot_stocks ADD CONSTRAINT chk_slstock_quantity CHECK (quantity >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('semen_lot_stocks');
    }
};
