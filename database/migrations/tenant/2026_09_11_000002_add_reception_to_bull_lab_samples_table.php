<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tube learns two things it never knew: in which delivery it arrived, and on which day it
 * was actually drawn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bull_lab_samples', function (Blueprint $table): void {
            // ADR-20: the discrepancy is per tube. Nine of ten arrived, and the system has to
            // be able to say which one is missing.
            $table->enum('reception_status', ['PENDING', 'RECEIVED', 'MISSING', 'DAMAGED'])
                ->default('PENDING')
                ->after('extraction_act_id');

            // The delivery that accounted for this tube, arrived or not: the discrepancy has to
            // hang off the document that found it.
            $table->foreignId('sample_reception_id')->nullable()->after('reception_status')
                ->constrained('sample_receptions')->nullOnDelete();

            // ADR-26: ADR-4 compares negative rounds by date, and one act may span two working
            // days. Read from the act the comparison produces false negatives of clearance.
            $table->date('extracted_on')->nullable()->after('sample_reception_id');

            $table->index(['company_id', 'reception_status'], 'bls_company_reception_idx');
        });

        // Every tube already in the system was drawn on the day its act was opened: that was the
        // only date the model could express until now.
        DB::table('bull_lab_samples')->whereNull('extracted_on')->update([
            'extracted_on' => DB::raw('sample_date'),
        ]);

        // A tube the laboratory already reported on demonstrably reached a bench, so it is
        // backfilled as RECEIVED. Leaving it PENDING would be the honest-looking option and the
        // wrong one: ADR-17 extended stops counting unreceived tubes, and every bull already
        // cleared by historical evidence would silently lose his clearance overnight.
        //
        // Tubes still PENDING_RESULTS stay PENDING: nobody ever declared their arrival, and that
        // is precisely the gap this migration exists to make visible.
        DB::table('bull_lab_samples')
            ->whereIn('status', ['NEGATIVE_CLEARED', 'POSITIVE_DETECTED'])
            ->update(['reception_status' => 'RECEIVED']);
    }

    public function down(): void
    {
        Schema::table('bull_lab_samples', function (Blueprint $table): void {
            $table->dropIndex('bls_company_reception_idx');
            $table->dropConstrainedForeignId('sample_reception_id');
            $table->dropColumn(['reception_status', 'extracted_on']);
        });
    }
};
