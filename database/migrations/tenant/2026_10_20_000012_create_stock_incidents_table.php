<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Non-blocking stock notices (D5, D19). At most one OPEN notice per (warehouse, item, type): a
 * repeated event updates it. Same shape as entry_order_incidents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('stock_movement_id')->nullable(); // NULL when raised by a critical-level edit (H3)
            $table->string('type', 24);                              // NEGATIVE_BALANCE | CRITICAL_STOCK | COUNT_DISCREPANCY
            $table->text('detail');
            $table->json('metadata')->nullable();
            $table->string('status', 10)->default('OPEN');           // OPEN | RESOLVED
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('raised_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'idx_sinc_company_status');
            $table->index(['company_id', 'item_id'], 'idx_sinc_company_item');

            $table->foreign(['warehouse_id', 'company_id'])          // stock_incidents_warehouse_id_company_id_foreign
                ->references(['id', 'company_id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['item_id', 'company_id'])               // stock_incidents_item_id_company_id_foreign
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign(['stock_movement_id', 'company_id'])     // stock_incidents_stock_movement_id_company_id_foreign
                ->references(['id', 'company_id'])->on('stock_movements')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE stock_incidents ADD CONSTRAINT chk_sinc_type
                CHECK (type IN ('NEGATIVE_BALANCE','CRITICAL_STOCK','COUNT_DISCREPANCY'))");
            DB::statement("ALTER TABLE stock_incidents ADD CONSTRAINT chk_sinc_status
                CHECK (status IN ('OPEN','RESOLVED'))");
        }

        $this->createOneOpenGuard();
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_incidents');
    }

    /** At most one OPEN notice per (warehouse, item, type). */
    private function createOneOpenGuard(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX uq_sinc_one_open
                ON stock_incidents (warehouse_id, item_id, type) WHERE status = 'OPEN'");

            return;
        }

        DB::statement("CREATE UNIQUE INDEX uq_sinc_one_open
            ON stock_incidents ((IF(status = 'OPEN', warehouse_id, NULL)), item_id, type)");
    }
};
