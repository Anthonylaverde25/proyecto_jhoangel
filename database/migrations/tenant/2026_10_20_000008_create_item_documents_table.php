<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock operations (DS14): purchase receipt, initial stock, consumption, count, return to provider,
 * reversal. Append-only. A purchase receipt or a return is backed by a provider document and is only
 * reversed by voiding that document (DS20).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedInteger('number');                       // per company (DS3)
            $table->string('type', 24);  // PURCHASE | INITIAL | CONSUMPTION | COUNT_ADJUSTMENT | RETURN_TO_PROVIDER | REVERSAL
            $table->unsignedBigInteger('warehouse_id');              // every operation moves (or counts) stock
            $table->unsignedBigInteger('fiscal_document_id')->nullable(); // PURCHASE, RETURN_TO_PROVIDER and their REVERSAL
            $table->unsignedBigInteger('batch_id')->nullable();      // CONSUMPTION and its REVERSAL (D22)
            $table->unsignedInteger('heads_treated')->nullable();    // declared, required with batch_id
            $table->timestamp('occurred_at');                        // declared date; never reorders the ledger (D4)
            $table->unsignedBigInteger('reverses_document_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number'], 'uq_idoc_company_number');
            $table->unique(['id', 'company_id'], 'uq_idoc_id_company');
            $table->unique('reverses_document_id', 'uq_idoc_reverses');          // reversed once (I4)
            $table->unique(['company_id', 'idempotency_key'], 'uq_idoc_company_idempotency');
            $table->index(['company_id', 'type', 'occurred_at'], 'idx_idoc_company_type_date');
            $table->index(['company_id', 'batch_id'], 'idx_idoc_company_batch');
            $table->index(['company_id', 'fiscal_document_id'], 'idx_idoc_company_fiscal');

            $table->foreign(['warehouse_id', 'company_id'])          // item_documents_warehouse_id_company_id_foreign
                ->references(['id', 'company_id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['fiscal_document_id', 'company_id'])    // item_documents_fiscal_document_id_company_id_foreign
                ->references(['id', 'company_id'])->on('fiscal_documents')->restrictOnDelete();
            $table->foreign(['batch_id', 'company_id'])              // item_documents_batch_id_company_id_foreign
                ->references(['id', 'company_id'])->on('batches')->restrictOnDelete();
        });

        Schema::table('item_documents', function (Blueprint $table) {
            $table->foreign(['reverses_document_id', 'company_id']) // item_documents_reverses_document_id_company_id_foreign
                ->references(['id', 'company_id'])->on('item_documents')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            $checks = [
                'chk_idoc_type' => "type IN ('PURCHASE','INITIAL','CONSUMPTION','COUNT_ADJUSTMENT','RETURN_TO_PROVIDER','REVERSAL')",
                'chk_idoc_fiscal_required' => "type NOT IN ('PURCHASE','RETURN_TO_PROVIDER') OR fiscal_document_id IS NOT NULL",
                'chk_idoc_fiscal_scope' => "fiscal_document_id IS NULL OR type IN ('PURCHASE','RETURN_TO_PROVIDER','REVERSAL')",
                'chk_idoc_batch_scope' => "batch_id IS NULL OR type IN ('CONSUMPTION','REVERSAL')",
                'chk_idoc_heads' => '(batch_id IS NULL AND heads_treated IS NULL) OR (batch_id IS NOT NULL AND heads_treated > 0)',
                'chk_idoc_reversal' => "(type = 'REVERSAL') = (reverses_document_id IS NOT NULL)",
                'chk_idoc_reason' => "type <> 'REVERSAL' OR reason IS NOT NULL",
                'chk_idoc_number' => 'number > 0',
            ];
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE item_documents ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }

        $this->createOneOperationPerFiscalDocumentGuard();
    }

    public function down(): void
    {
        Schema::dropIfExists('item_documents');
    }

    /**
     * A provider document generates at most one stock operation; its reversal shares the same
     * fiscal_document_id. MySQL: functional unique index. SQLite: partial unique index.
     */
    private function createOneOperationPerFiscalDocumentGuard(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX uq_idoc_one_per_fiscal
                ON item_documents (fiscal_document_id) WHERE type <> 'REVERSAL'");

            return;
        }

        DB::statement("CREATE UNIQUE INDEX uq_idoc_one_per_fiscal
            ON item_documents ((IF(type <> 'REVERSAL', fiscal_document_id, NULL)))");
    }
};
