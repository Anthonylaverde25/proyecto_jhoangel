<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The semen entry (S14): a valued inbound operation that no provider document backs. Its optional
 * provider is only where the semen came from: no debt, invoice or account.
 */
return new class extends Migration
{
    private const TYPES_D = "'PURCHASE','INITIAL','CONSUMPTION','COUNT_ADJUSTMENT','RETURN_TO_PROVIDER','REVERSAL','DELIVERY_RECEIPT','VALUATION','TRANSFER_OUT','TRANSFER_IN'";
    private const TYPES_SA = self::TYPES_D . ",'SEMEN_RECEIPT'";

    public function up(): void
    {
        // SQLite rebuilds the table to add a foreign key and would drop this partial index: same dance as D.
        Schema::table('item_documents', function (Blueprint $table) {
            $table->dropIndex('uq_idoc_one_per_fiscal_wh');
        });

        Schema::table('item_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_id')->nullable()->after('warehouse_id');   // SEMEN_RECEIPT only
            $table->index(['company_id', 'provider_id'], 'idx_idoc_company_provider');
            $table->foreign(['provider_id', 'company_id'])           // item_documents_provider_id_company_id_foreign
                ->references(['id', 'company_id'])->on('providers')->restrictOnDelete();
        });
        $this->createOnePerFiscalAndWarehouseGuard();

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE item_documents DROP CHECK chk_idoc_type');
            DB::statement('ALTER TABLE item_documents ADD CONSTRAINT chk_idoc_type CHECK (type IN (' . self::TYPES_SA . '))');
            DB::statement("ALTER TABLE item_documents ADD CONSTRAINT chk_idoc_provider_scope CHECK (provider_id IS NULL OR type = 'SEMEN_RECEIPT')");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE item_documents DROP CHECK chk_idoc_provider_scope');
            DB::statement('ALTER TABLE item_documents DROP CHECK chk_idoc_type');
            DB::statement('ALTER TABLE item_documents ADD CONSTRAINT chk_idoc_type CHECK (type IN (' . self::TYPES_D . '))');
        }
        Schema::table('item_documents', function (Blueprint $table) {
            $table->dropIndex('uq_idoc_one_per_fiscal_wh');
        });
        Schema::table('item_documents', function (Blueprint $table) {
            $table->dropForeign(['provider_id', 'company_id']);
            $table->dropIndex('idx_idoc_company_provider');
            $table->dropColumn('provider_id');
        });
        $this->createOnePerFiscalAndWarehouseGuard();
    }

    /** Delivery D's guard: one operation per provider document and warehouse, reversals aside. */
    private function createOnePerFiscalAndWarehouseGuard(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX uq_idoc_one_per_fiscal_wh
                ON item_documents (fiscal_document_id, warehouse_id) WHERE type <> 'REVERSAL'");

            return;
        }

        DB::statement("CREATE UNIQUE INDEX uq_idoc_one_per_fiscal_wh
            ON item_documents ((IF(type <> 'REVERSAL', fiscal_document_id, NULL)), warehouse_id)");
    }
};
