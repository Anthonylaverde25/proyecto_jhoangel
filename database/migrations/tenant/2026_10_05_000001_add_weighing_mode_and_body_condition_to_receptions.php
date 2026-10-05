<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ING-03 can be weighed animal by animal or with one average for the arrival, and it records
 * the body condition (official scale 1 to 5) of each caravan.
 *
 * - `entry_order_receipt_sheets.weighing_mode`: chosen when the sheet is issued, because it changes
 *   the paper — a weight column per line (INDIVIDUAL) or one cell in the header (AVERAGE) — and the
 *   scan reads the paper the sheet says it printed.
 * - `caravan_weights.method`: an average assigned to a caravan is not a weighing of that caravan,
 *   and the record says so. Null on the rows that existed before: individual weighings.
 * - `caravan_body_conditions`: the history of each caravan's body condition, like its weights,
 *   with the document that recorded it (the ING-03 sheet, when it came from one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entry_order_receipt_sheets', function (Blueprint $table) {
            // INDIVIDUAL | AVERAGE
            $table->string('weighing_mode', 12)->default('INDIVIDUAL')->after('status');
        });

        Schema::table('caravan_weights', function (Blueprint $table) {
            // INDIVIDUAL | AVERAGE; null = individual (rows from before the column).
            $table->string('method', 12)->nullable()->after('weighing_date');
        });

        Schema::create('caravan_body_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caravan_id')->constrained('caravans')->onDelete('cascade');

            // Official scale 1 to 5, in steps of 0.5.
            $table->decimal('score', 2, 1);
            $table->boolean('current')->default(false);
            $table->date('assessed_at');

            // Which record it came from: ENTRY_RECEPTION | MANUAL. The sheet, when it was an ING-03.
            $table->string('source', 24);
            $table->foreignId('entry_order_receipt_sheet_id')->nullable()->constrained('entry_order_receipt_sheets')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['caravan_id', 'current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caravan_body_conditions');

        Schema::table('caravan_weights', function (Blueprint $table) {
            $table->dropColumn('method');
        });

        Schema::table('entry_order_receipt_sheets', function (Blueprint $table) {
            $table->dropColumn('weighing_mode');
        });
    }
};
