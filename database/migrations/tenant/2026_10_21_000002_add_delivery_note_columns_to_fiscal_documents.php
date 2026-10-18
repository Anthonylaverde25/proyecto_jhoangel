<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A delivery note says whether it carries prices (DC14) and, once converted, which invoice it went
 * into (DC5, DC10). An unvalued delivery note has no amounts: NULL, never a fabricated 0 (H2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->boolean('is_valued')->nullable()->after('issued_at');                  // DELIVERY_NOTE only
            $table->unsignedBigInteger('invoiced_by_document_id')->nullable()->after('related_fiscal_document_id');
            $table->decimal('lines_amount', 18, 4)->nullable()->change();
            $table->decimal('line_taxes_amount', 18, 4)->nullable()->change();
            $table->decimal('document_taxes_amount', 18, 4)->nullable()->change();
            $table->decimal('total_amount', 18, 4)->nullable()->change();

            $table->index(['company_id', 'invoiced_by_document_id'], 'idx_fdoc_company_invoiced_by');
        });

        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->foreign(['invoiced_by_document_id', 'company_id'])   // fiscal_documents_invoiced_by_document_id_company_id_foreign
                ->references(['id', 'company_id'])->on('fiscal_documents')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            $checks = [
                // Amounts are missing only on an unvalued delivery note.
                'chk_fdoc_valued_amounts' => '(is_valued IS NULL OR is_valued = 1)
                    = (lines_amount IS NOT NULL AND line_taxes_amount IS NOT NULL
                       AND document_taxes_amount IS NOT NULL AND total_amount IS NOT NULL)',
                // Only a delivery note (is_valued set) points at the invoice it went into.
                'chk_fdoc_invoiced_by' => 'invoiced_by_document_id IS NULL OR is_valued IS NOT NULL',
            ];
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE fiscal_documents ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }

        $this->restoreSqliteDuplicateGuard();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE fiscal_documents DROP CHECK chk_fdoc_valued_amounts');
            DB::statement('ALTER TABLE fiscal_documents DROP CHECK chk_fdoc_invoiced_by');
        }

        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropForeign(['invoiced_by_document_id', 'company_id']);
            $table->dropIndex('idx_fdoc_company_invoiced_by');
            $table->dropColumn(['is_valued', 'invoiced_by_document_id']);
            $table->decimal('lines_amount', 18, 4)->nullable(false)->change();
            $table->decimal('line_taxes_amount', 18, 4)->nullable(false)->change();
            $table->decimal('document_taxes_amount', 18, 4)->nullable(false)->change();
            $table->decimal('total_amount', 18, 4)->nullable(false)->change();
        });

        $this->restoreSqliteDuplicateGuard();
    }

    /**
     * On SQLite, change() and a new foreign key rebuild the table, bringing the partial unique index of DS21 back
     * without its WHERE: a voided document would then block loading it again. MySQL keeps its functional index.
     */
    private function restoreSqliteDuplicateGuard(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS uq_fdoc_registered_number');
        DB::statement("CREATE UNIQUE INDEX uq_fdoc_registered_number
            ON fiscal_documents (company_id, provider_id, fiscal_document_type_id, point_of_sale, number)
            WHERE status = 'REGISTERED'");
    }
};
