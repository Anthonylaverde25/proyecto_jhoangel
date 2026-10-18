<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A difference between a delivery note and the invoice it went into (DC7). Raised when the invoice is
 * registered, closed by hand with a note (DC8), cancelled when the invoice is voided. Same shape as
 * entry_order_incidents and stock_incidents. Foreign keys are named by hand: the automatic names pass
 * MySQL's 64-character limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_document_discrepancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('invoice_document_id');
            $table->unsignedBigInteger('delivery_note_id')->nullable();       // NULL on EXTRA_LINE and TOTAL
            $table->unsignedBigInteger('invoice_line_id')->nullable();        // NULL on MISSING_LINE and TOTAL
            $table->unsignedBigInteger('delivery_note_line_id')->nullable();  // NULL on EXTRA_LINE and TOTAL
            $table->unsignedBigInteger('item_id')->nullable();                // NULL on TOTAL
            $table->string('type', 16);           // QUANTITY | PRICE | MISSING_LINE | EXTRA_LINE | TOTAL
            $table->decimal('expected_value', 18, 6)->nullable();             // as on the delivery note
            $table->decimal('actual_value', 18, 6)->nullable();               // as on the invoice
            $table->text('detail');
            $table->string('status', 10)->default('OPEN');                    // OPEN | RESOLVED | CANCELLED
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('raised_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'idx_fdisc_company_status');
            $table->index(['company_id', 'invoice_document_id'], 'idx_fdisc_company_invoice');
            $table->index(['company_id', 'delivery_note_id'], 'idx_fdisc_company_delivery');

            $table->foreign(['invoice_document_id', 'company_id'], 'fk_fdisc_invoice')
                ->references(['id', 'company_id'])->on('fiscal_documents')->restrictOnDelete();
            $table->foreign(['delivery_note_id', 'company_id'], 'fk_fdisc_delivery_note')
                ->references(['id', 'company_id'])->on('fiscal_documents')->restrictOnDelete();
            $table->foreign(['invoice_line_id', 'company_id'], 'fk_fdisc_invoice_line')
                ->references(['id', 'company_id'])->on('fiscal_document_lines')->restrictOnDelete();
            $table->foreign(['delivery_note_line_id', 'company_id'], 'fk_fdisc_delivery_line')
                ->references(['id', 'company_id'])->on('fiscal_document_lines')->restrictOnDelete();
            $table->foreign(['item_id', 'company_id'], 'fk_fdisc_item')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            $checks = [
                'chk_fdisc_type' => "type IN ('QUANTITY','PRICE','MISSING_LINE','EXTRA_LINE','TOTAL')",
                'chk_fdisc_status' => "status IN ('OPEN','RESOLVED','CANCELLED')",
                'chk_fdisc_resolved' => "(status = 'RESOLVED') = (resolution IS NOT NULL AND resolved_at IS NOT NULL)",
                'chk_fdisc_shape' => "(type IN ('QUANTITY','PRICE') AND invoice_line_id IS NOT NULL AND delivery_note_line_id IS NOT NULL)
                    OR (type = 'MISSING_LINE' AND invoice_line_id IS NULL AND delivery_note_line_id IS NOT NULL)
                    OR (type = 'EXTRA_LINE' AND invoice_line_id IS NOT NULL AND delivery_note_line_id IS NULL)
                    OR (type = 'TOTAL' AND invoice_line_id IS NULL AND delivery_note_line_id IS NULL AND item_id IS NULL)",
            ];
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE fiscal_document_discrepancies ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_discrepancies');
    }
};
