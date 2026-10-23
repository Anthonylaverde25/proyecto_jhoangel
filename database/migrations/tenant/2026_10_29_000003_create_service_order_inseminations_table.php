<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One straw application on one female of an AI service order (semen S-B, IA6). It records where
 * the straws came from (tank and lot) and the consumption line that took them out (IA7). A wrong
 * one is voided, never deleted (IA9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_order_inseminations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('service_order_id');
            $table->unsignedBigInteger('female_caravan_id');
            $table->date('inseminated_on');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('semen_lot_id')->nullable();          // null = straws without lot (SL6)
            $table->unsignedInteger('straws_used')->default(1);
            $table->unsignedBigInteger('warehouse_id');                      // CRYO_TANK the straws left from
            $table->unsignedBigInteger('item_document_line_id')->nullable(); // consumption line that took them
            $table->text('notes')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['service_order_id', 'female_caravan_id'], 'idx_soi_order_female');
            $table->index(['company_id', 'item_id'], 'idx_soi_company_item');
            $table->index(['company_id', 'semen_lot_id'], 'idx_soi_company_lot');
            $table->index(['company_id', 'item_document_line_id'], 'idx_soi_company_line');

            $table->foreign('service_order_id', 'fk_soi_order')
                ->references('id')->on('service_orders')->restrictOnDelete();
            $table->foreign(['service_order_id', 'female_caravan_id'], 'fk_soi_order_female')
                ->references(['service_order_id', 'female_caravan_id'])->on('service_order_females')->restrictOnDelete();
            $table->foreign(['item_id', 'company_id'], 'fk_soi_item')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign(['semen_lot_id', 'item_id'], 'fk_soi_lot_item')
                ->references(['id', 'item_id'])->on('semen_lots')->restrictOnDelete();
            $table->foreign(['warehouse_id', 'company_id'], 'fk_soi_warehouse')
                ->references(['id', 'company_id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['item_document_line_id', 'company_id'], 'fk_soi_line')
                ->references(['id', 'company_id'])->on('item_document_lines')->restrictOnDelete();
        });

        $this->createOneLivePerDayGuard();

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE service_order_inseminations ADD CONSTRAINT chk_soi_straws CHECK (straws_used >= 1)');
            DB::statement('ALTER TABLE service_order_inseminations ADD CONSTRAINT chk_soi_void CHECK ((voided_at IS NULL) = (void_reason IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_inseminations');
    }

    /** IA6: one live insemination per female and day in an order; voided ones never collide. */
    private function createOneLivePerDayGuard(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX uq_soi_one_live_per_day
                ON service_order_inseminations (service_order_id, female_caravan_id, inseminated_on) WHERE voided_at IS NULL');

            return;
        }

        Schema::table('service_order_inseminations', function (Blueprint $table) {
            $table->unsignedBigInteger('live_female_guard')
                ->nullable()
                ->storedAs('CASE WHEN voided_at IS NULL THEN female_caravan_id END')
                ->comment('Equals female_caravan_id while live; enforces one live insemination per female and day');
            $table->unique(['service_order_id', 'inseminated_on', 'live_female_guard'], 'uq_soi_one_live_per_day');
        });
    }
};
