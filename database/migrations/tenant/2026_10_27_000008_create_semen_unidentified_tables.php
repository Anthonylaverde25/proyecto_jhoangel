<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Straws moved without a lot (SL6): a semen entry now, an insemination in S-B. Each such line leaves
 * one pending entry with its notice; identifying it assigns the quantity to one or more lots without
 * touching the ledger (SU3, SU7). Per tank: item balance = lots + signed pending (SL3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semen_unidentified_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('item_document_line_id');      // the line without lot
            $table->unsignedBigInteger('stock_incident_id')->nullable(); // its "Identificar partida" notice
            $table->tinyInteger('direction');                         // +1 entry, -1 insemination (S-B)
            $table->decimal('quantity', 18, 4);                       // straws
            $table->decimal('identified_quantity', 18, 4)->default(0);
            $table->string('status', 10)->default('OPEN');            // OPEN | RESOLVED | VOIDED
            $table->timestamps();

            $table->unique(['id', 'company_id'], 'uq_sue_id_company');
            $table->unique('item_document_line_id', 'uq_sue_line');
            $table->index(['company_id', 'item_id', 'warehouse_id', 'status'], 'idx_sue_item_warehouse_status');
            // Named by hand: the default names pass MySQL's 64-character limit.
            $table->foreign(['item_id', 'company_id'], 'fk_sue_item')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign(['warehouse_id', 'company_id'], 'fk_sue_warehouse')
                ->references(['id', 'company_id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['item_document_line_id', 'company_id'], 'fk_sue_line')
                ->references(['id', 'company_id'])->on('item_document_lines')->restrictOnDelete();
            $table->foreign('stock_incident_id', 'fk_sue_incident')->references('id')->on('stock_incidents')->nullOnDelete();
        });

        Schema::create('semen_lot_identifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('semen_unidentified_entry_id');
            $table->unsignedBigInteger('semen_lot_id');
            $table->decimal('quantity', 18, 4);
            $table->foreignId('identified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();               // when its entry's receipt is voided (SU8)
            $table->timestamps();

            $table->index(['company_id', 'semen_lot_id'], 'idx_sli_company_lot');
            $table->foreign(['semen_unidentified_entry_id', 'company_id'], 'fk_sli_entry')
                ->references(['id', 'company_id'])->on('semen_unidentified_entries')->restrictOnDelete();
            $table->foreign(['semen_lot_id', 'company_id'], 'fk_sli_lot')
                ->references(['id', 'company_id'])->on('semen_lots')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            $checks = [
                'chk_sue_direction' => 'direction IN (-1, 1)',
                'chk_sue_quantities' => 'quantity > 0 AND identified_quantity >= 0 AND identified_quantity <= quantity',
                'chk_sue_status' => "status IN ('OPEN','RESOLVED','VOIDED')",
                'chk_sue_resolved' => "status <> 'RESOLVED' OR identified_quantity = quantity",
            ];
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE semen_unidentified_entries ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
            DB::statement('ALTER TABLE semen_lot_identifications ADD CONSTRAINT chk_sli_quantity CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('semen_lot_identifications');
        Schema::dropIfExists('semen_unidentified_entries');
    }
};
