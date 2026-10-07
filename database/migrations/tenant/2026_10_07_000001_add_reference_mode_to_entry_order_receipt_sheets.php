<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How an ING-03 sheet asks for the breed, coat and category of each line, when the order leaves
 * them to each animal: written in words (WRITTEN: RAZA, PELAJE and CATEGORÍA columns) or by the
 * reference printed in the header (CODE: a breed letter and a category number). Chosen when the
 * sheet is issued, like the weighing, because it changes the paper — and the scan reads the paper
 * the sheet says it printed. Sheets issued before the column printed codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entry_order_receipt_sheets', function (Blueprint $table) {
            // WRITTEN | CODE
            $table->string('reference_mode', 8)->default('WRITTEN')->after('weighing_mode');
        });

        // What was already on paper used letters and numbers.
        DB::table('entry_order_receipt_sheets')->update(['reference_mode' => 'CODE']);
    }

    public function down(): void
    {
        Schema::table('entry_order_receipt_sheets', function (Blueprint $table) {
            $table->dropColumn('reference_mode');
        });
    }
};
