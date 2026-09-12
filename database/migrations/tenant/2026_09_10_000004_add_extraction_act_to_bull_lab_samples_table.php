<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tube is born under the extraction act and is later resolved by the laboratory report:
 *
 *   extraction_act_id      -> always set from now on, immutable, chain of custody origin
 *   diagnostic_protocol_id -> the LAB_REPORT, NULL while the tube is PENDING_RESULTS
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bull_lab_samples', function (Blueprint $table) {
            $table->foreignId('extraction_act_id')
                ->nullable()
                ->after('diagnostic_protocol_id')
                ->constrained('diagnostic_protocols')
                ->restrictOnDelete()
                ->comment('Signed act that created this tube');
        });

        Schema::table('bull_lab_samples', function (Blueprint $table) {
            // The pre-existing unique index hangs off diagnostic_protocol_id, which stays NULL
            // until the laboratory reports. MySQL allows unlimited NULLs in a unique index, so
            // pending tubes had no duplicate protection at all.
            $table->unique(
                ['extraction_act_id', 'caravan_id', 'pathogen_id', 'sample_round'],
                'bls_act_caravan_pathogen_round_unq'
            );

            $table->index(['company_id', 'extraction_act_id', 'status'], 'bls_act_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bull_lab_samples', function (Blueprint $table) {
            $table->dropUnique('bls_act_caravan_pathogen_round_unq');
            $table->dropIndex('bls_act_status_idx');
            $table->dropConstrainedForeignId('extraction_act_id');
        });
    }
};
