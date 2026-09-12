<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-3: disambiguates `veterinary_diagnoses.veterinarian_id`, which pointed to `users`
 * and therefore could not identify an external professional without a login account (F3).
 *
 *  - `veterinarian_id`  -> renamed to `diagnosed_by_user_id` (who operated the system).
 *  - `veterinarian_id`  -> recreated as FK to `veterinarians` (who is legally responsible).
 *
 * The `status` enum is deliberately untouched (ADR-2): negatives never live in this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isMySql = DB::connection()->getDriverName() === 'mysql';

        // Step 1: MySQL keeps the constraint bound to the old column name, so it must go first.
        if ($isMySql) {
            Schema::table('veterinary_diagnoses', function (Blueprint $table) {
                $table->dropForeign(['veterinarian_id']);
            });
        }

        // Step 2: the legacy column pointed to `users`; its real meaning is "who operated the system".
        Schema::table('veterinary_diagnoses', function (Blueprint $table) {
            $table->renameColumn('veterinarian_id', 'diagnosed_by_user_id');
        });

        if ($isMySql) {
            Schema::table('veterinary_diagnoses', function (Blueprint $table) {
                $table->foreign('diagnosed_by_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        Schema::table('veterinary_diagnoses', function (Blueprint $table) {
            // Step 3: the legally responsible professional, who may have no login at all (Use Case 2).
            $table->foreignId('veterinarian_id')
                ->nullable()
                ->after('diagnosed_by_user_id')
                ->constrained('veterinarians')
                ->nullOnDelete()
                ->comment('Acting professional (catalogue), independent from any login account');

            $table->foreignId('diagnostic_protocol_id')
                ->nullable()
                ->after('company_id')
                ->constrained('diagnostic_protocols')
                ->nullOnDelete()
                ->comment('Parent report that originated this finding');

            $table->foreignId('bull_lab_sample_id')
                ->nullable()
                ->after('diagnostic_protocol_id')
                ->constrained('bull_lab_samples')
                ->nullOnDelete()
                ->comment('Source sample when the finding is derived from a POSITIVE_DETECTED result');
        });

        Schema::table('veterinary_diagnoses', function (Blueprint $table) {
            $table->index(['company_id', 'caravan_id', 'diagnostic_protocol_id'], 'vd_comp_caravan_protocol_idx');
        });
    }

    public function down(): void
    {
        $isMySql = DB::connection()->getDriverName() === 'mysql';

        Schema::table('veterinary_diagnoses', function (Blueprint $table) use ($isMySql) {
            $table->dropIndex('vd_comp_caravan_protocol_idx');
            $table->dropConstrainedForeignId('bull_lab_sample_id');
            $table->dropConstrainedForeignId('diagnostic_protocol_id');
            $table->dropConstrainedForeignId('veterinarian_id');

            if ($isMySql) {
                $table->dropForeign(['diagnosed_by_user_id']);
            }
        });

        Schema::table('veterinary_diagnoses', function (Blueprint $table) {
            $table->renameColumn('diagnosed_by_user_id', 'veterinarian_id');
        });

        if ($isMySql) {
            Schema::table('veterinary_diagnoses', function (Blueprint $table) {
                $table->foreign('veterinarian_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }
};
