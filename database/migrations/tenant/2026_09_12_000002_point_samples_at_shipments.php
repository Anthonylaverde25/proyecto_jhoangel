<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tube points at the shipment that carried it. NULL is not a gap: it means the tube is still
 * in the professional's hands.
 *
 * That single column replaces `reception_status`, whose job was to tell "never arrived" from
 * "arrived, no result yet". Both are readable from this column plus the result:
 *
 *   NULL + PENDING_RESULTS -> in the professional's hands
 *   NULL + resolved        -> processed in house, never shipped
 *   set  + PENDING_RESULTS -> dispatched, waiting on the laboratory
 *   set  + resolved        -> closed
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bull_lab_samples', function (Blueprint $table): void {
            $table->dropIndex('bls_company_reception_idx');
            $table->dropConstrainedForeignId('sample_reception_id');
            $table->dropColumn('reception_status');
        });

        Schema::table('bull_lab_samples', function (Blueprint $table): void {
            $table->foreignId('sample_shipment_id')->nullable()->after('extraction_act_id')
                ->constrained('sample_shipments')->nullOnDelete();

            $table->index(['company_id', 'sample_shipment_id'], 'bls_company_shipment_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bull_lab_samples', function (Blueprint $table): void {
            $table->dropIndex('bls_company_shipment_idx');
            $table->dropConstrainedForeignId('sample_shipment_id');
        });

        Schema::table('bull_lab_samples', function (Blueprint $table): void {
            $table->enum('reception_status', ['PENDING', 'RECEIVED', 'MISSING', 'DAMAGED'])
                ->default('PENDING')->after('extraction_act_id');
            $table->foreignId('sample_reception_id')->nullable()->after('reception_status')
                ->constrained('sample_receptions')->nullOnDelete();
            $table->index(['company_id', 'reception_status'], 'bls_company_reception_idx');
        });
    }
};
