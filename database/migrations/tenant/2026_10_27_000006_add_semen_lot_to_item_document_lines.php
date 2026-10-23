<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lot a stock line of semen moves (SL1). The FK pairs the lot with the line's item, so a line can
 * never point at a lot of another semen (SL2). NULL for every other item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_document_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('semen_lot_id')->nullable()->after('item_id');
            $table->index(['company_id', 'semen_lot_id'], 'idx_idl_company_semen_lot');
            $table->foreign(['semen_lot_id', 'item_id'])            // item_document_lines_semen_lot_id_item_id_foreign
                ->references(['id', 'item_id'])->on('semen_lots')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('item_document_lines', function (Blueprint $table) {
            $table->dropForeign(['semen_lot_id', 'item_id']);
            $table->dropIndex('idx_idl_company_semen_lot');
            $table->dropColumn('semen_lot_id');
        });
    }
};
