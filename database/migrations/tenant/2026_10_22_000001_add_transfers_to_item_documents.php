<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transfers between warehouses of one company (delivery D, DD7): a TRANSFER_OUT in the origin and a
 * TRANSFER_IN in the destination that points to it. A provider document now generates at most one
 * operation per warehouse: an invoice converted from delivery notes values each lot where it entered (H1).
 */
return new class extends Migration
{
    private const TYPES_C = "'PURCHASE','INITIAL','CONSUMPTION','COUNT_ADJUSTMENT','RETURN_TO_PROVIDER','REVERSAL','DELIVERY_RECEIPT','VALUATION'";
    private const TYPES_D = "'PURCHASE','INITIAL','CONSUMPTION','COUNT_ADJUSTMENT','RETURN_TO_PROVIDER','REVERSAL','DELIVERY_RECEIPT','VALUATION','TRANSFER_OUT','TRANSFER_IN'";

    public function up(): void
    {
        Schema::table('item_documents', function (Blueprint $table) {
            $table->dropIndex('uq_idoc_one_per_fiscal');
        });

        Schema::table('item_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('paired_document_id')->nullable()->after('reverses_document_id'); // TRANSFER_IN → its TRANSFER_OUT
            $table->unique('paired_document_id', 'uq_idoc_paired');                                      // one entry per exit
            $table->foreign(['paired_document_id', 'company_id'])  // item_documents_paired_document_id_company_id_foreign
                ->references(['id', 'company_id'])->on('item_documents')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE item_documents DROP CHECK chk_idoc_type');
            DB::statement('ALTER TABLE item_documents ADD CONSTRAINT chk_idoc_type CHECK (type IN (' . self::TYPES_D . '))');
            DB::statement("ALTER TABLE item_documents ADD CONSTRAINT chk_idoc_paired
                CHECK ((type = 'TRANSFER_IN') = (paired_document_id IS NOT NULL))");
        }

        // Created last: on SQLite the foreign key rebuilds the table and drops the WHERE of partial indexes (H8).
        $this->createOnePerFiscalAndWarehouseGuard();
    }

    public function down(): void
    {
        Schema::table('item_documents', function (Blueprint $table) {
            $table->dropIndex('uq_idoc_one_per_fiscal_wh');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE item_documents DROP CHECK chk_idoc_paired');
            DB::statement('ALTER TABLE item_documents DROP CHECK chk_idoc_type');
            DB::statement('ALTER TABLE item_documents ADD CONSTRAINT chk_idoc_type CHECK (type IN (' . self::TYPES_C . '))');
        }

        Schema::table('item_documents', function (Blueprint $table) {
            $table->dropForeign(['paired_document_id', 'company_id']);
            $table->dropUnique('uq_idoc_paired');
            $table->dropColumn('paired_document_id');
        });

        $this->createOnePerFiscalGuard();
    }

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

    /** The B2 guard, restored by down(). */
    private function createOnePerFiscalGuard(): void
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
