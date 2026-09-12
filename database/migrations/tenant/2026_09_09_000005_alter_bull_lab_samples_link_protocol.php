<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-1: `bull_lab_samples` is the single source of truth for laboratory results.
 * This migration links every determination to its parent report and unifies the
 * sample type enum (F1, F16).
 *
 * The legacy loose `protocol_number` column is kept for backwards compatibility with
 * already ingested rows; it is deprecated in favour of the relation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bull_lab_samples', function (Blueprint $table) {
            $table->foreignId('diagnostic_protocol_id')
                ->nullable()
                ->after('caravan_id')
                ->constrained('diagnostic_protocols')
                ->nullOnDelete()
                ->comment('Parent lab report backing this determination');

            $table->foreignId('veterinarian_id')
                ->nullable()
                ->after('diagnostic_protocol_id')
                ->constrained('veterinarians')
                ->nullOnDelete()
                ->comment('Acting professional, may differ from the system user');
        });

        Schema::table('bull_lab_samples', function (Blueprint $table) {
            // F9: the same protocol cannot record the same pathogen twice for the same bull and round.
            $table->unique(
                ['diagnostic_protocol_id', 'caravan_id', 'pathogen_id', 'sample_round'],
                'bls_protocol_caravan_pathogen_round_unq'
            );

            // Supports the aptitude engine query: latest valid rounds per bull and pathogen.
            $table->index(
                ['company_id', 'caravan_id', 'pathogen_id', 'sample_round', 'sample_date'],
                'bls_aptitude_lookup_idx'
            );
        });

        // F16: unify sample types. CLINICAL_EXAM is intentionally excluded: a clinical exam is not
        // a laboratory sample and belongs in `bull_health_evaluations`.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE bull_lab_samples MODIFY COLUMN sample_type
                 ENUM('PREPUCE_SCRAPE', 'BLOOD_SEROLOGY', 'SEMEN_CULTURE', 'TUBERCULIN_TEST') NOT NULL"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE bull_lab_samples MODIFY COLUMN sample_type
                 ENUM('PREPUCE_SCRAPE', 'BLOOD_SEROLOGY') NOT NULL"
            );
        }

        Schema::table('bull_lab_samples', function (Blueprint $table) {
            $table->dropIndex('bls_aptitude_lookup_idx');
            $table->dropUnique('bls_protocol_caravan_pathogen_round_unq');
            $table->dropConstrainedForeignId('veterinarian_id');
            $table->dropConstrainedForeignId('diagnostic_protocol_id');
        });
    }
};
