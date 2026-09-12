<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-11 / ADR-12 / ADR-15: an extraction act and a laboratory report are two distinct legal
 * acts, signed by two different professionals. Until now both were crushed into a single row,
 * which made it impossible to answer "who drew the sample?" and "who reported it?" separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diagnostic_protocols', function (Blueprint $table) {
            // Everything loaded up to now is a laboratory report, so the default classifies
            // existing rows correctly without a data migration.
            $table->enum('protocol_type', ['EXTRACTION_ACT', 'LAB_REPORT'])
                ->default('LAB_REPORT')
                ->after('protocol_number')
                ->comment('EXTRACTION_ACT is signed at the chute; LAB_REPORT is issued by the laboratory');

            $table->foreignId('parent_protocol_id')
                ->nullable()
                ->after('protocol_type')
                ->constrained('diagnostic_protocols')
                ->restrictOnDelete()
                ->comment('A LAB_REPORT points at the EXTRACTION_ACT whose tubes it resolves');

            // ADR-12: chain of custody paperwork travelling with the cooler to the laboratory.
            $table->string('dispatch_note_number', 50)->nullable()->after('parent_protocol_id')
                ->comment('Dispatch note handed over with the tubes');
            $table->date('dispatched_at')->nullable()->after('dispatch_note_number')
                ->comment('When the tubes physically left the establishment');

            // ADR-15: freeze the institution exactly like the professional signature (ADR-8).
            // Renaming a laboratory must not rewrite historical documents.
            $table->string('signed_health_center_name', 150)->nullable()->after('signed_veterinarian_name');
            $table->string('signed_health_center_code', 50)->nullable()->after('signed_health_center_name');

            $table->index(['company_id', 'protocol_type', 'status'], 'dp_company_type_status_idx');
            $table->index(['parent_protocol_id'], 'dp_parent_idx');
        });

        // An EXTRACTION_ACT has no result date: the laboratory has not spoken yet.
        Schema::table('diagnostic_protocols', function (Blueprint $table) {
            $table->date('result_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('diagnostic_protocols', function (Blueprint $table) {
            $table->dropIndex('dp_company_type_status_idx');
            $table->dropIndex('dp_parent_idx');
            $table->dropConstrainedForeignId('parent_protocol_id');
            $table->dropColumn([
                'protocol_type',
                'dispatch_note_number',
                'dispatched_at',
                'signed_health_center_name',
                'signed_health_center_code',
            ]);
        });

        Schema::table('diagnostic_protocols', function (Blueprint $table) {
            $table->date('result_date')->nullable(false)->change();
        });
    }
};
