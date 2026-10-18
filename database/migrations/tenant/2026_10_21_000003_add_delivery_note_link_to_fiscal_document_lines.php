<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice line converted from a delivery note points at the line it came from (DC5): that link
 * is what compares both papers and values what arrived unpriced. A line of an unvalued delivery note
 * has no price (H1): the four amounts are NULL together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_document_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_note_line_id')->nullable()->after('fiscal_document_id');
            $table->decimal('unit_price', 18, 6)->nullable()->change();
            $table->decimal('line_amount', 18, 4)->nullable()->change();
            $table->decimal('cost_amount', 18, 4)->nullable()->change();
            $table->decimal('unit_cost', 18, 6)->nullable()->change();

            $table->index(['company_id', 'delivery_note_line_id'], 'idx_fdl_company_delivery_line');
        });

        Schema::table('fiscal_document_lines', function (Blueprint $table) {
            $table->foreign(['delivery_note_line_id', 'company_id'])     // fiscal_document_lines_delivery_note_line_id_company_id_foreign
                ->references(['id', 'company_id'])->on('fiscal_document_lines')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE fiscal_document_lines ADD CONSTRAINT chk_fdl_priced_together CHECK (
                (unit_price IS NULL) = (line_amount IS NULL)
                AND (line_amount IS NULL) = (cost_amount IS NULL)
                AND (cost_amount IS NULL) = (unit_cost IS NULL))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE fiscal_document_lines DROP CHECK chk_fdl_priced_together');
        }

        Schema::table('fiscal_document_lines', function (Blueprint $table) {
            $table->dropForeign(['delivery_note_line_id', 'company_id']);
            $table->dropIndex('idx_fdl_company_delivery_line');
            $table->dropColumn('delivery_note_line_id');
            $table->decimal('unit_price', 18, 6)->nullable(false)->change();
            $table->decimal('line_amount', 18, 4)->nullable(false)->change();
            $table->decimal('cost_amount', 18, 4)->nullable(false)->change();
            $table->decimal('unit_cost', 18, 6)->nullable(false)->change();
        });
    }
};
