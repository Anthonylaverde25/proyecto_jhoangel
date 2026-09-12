<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-24: the custody mode is frozen on the act when it is issued, because the catalogue flag
 * it derives from can change afterwards and the document must keep describing what happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diagnostic_protocols', function (Blueprint $table): void {
            $table->enum('custody_mode', ['COUNTERPARTY', 'DERIVED'])
                ->default('COUNTERPARTY')
                ->after('health_center_id');

            // Only populated in DERIVED mode. Deliberately NOT health_center_id: that column
            // backs the professional's signature and must never be contaminated by a third
            // party laboratory the professional has no relation with.
            $table->foreignId('derived_to_health_center_id')->nullable()->after('custody_mode')
                ->constrained('health_centers')->restrictOnDelete();

            // ADR-28: what the professional actually declared. The foreign key is bookkeeping
            // that the office can complete later; the name is the evidence.
            $table->string('derived_to_name', 150)->nullable()->after('derived_to_health_center_id')
                ->comment('Frozen copy of the external laboratory name');

            $table->index(['company_id', 'custody_mode'], 'dp_company_custody_idx');
        });

        // The column default is COUNTERPARTY, and leaving every historical act on it would make
        // each one claim a counterparty that, for a third party laboratory, never existed — the
        // exact kind of assertion-without-evidence ADR-25 exists to prevent. So the same rule the
        // use case applies is applied backwards: the institution's flag decides.
        DB::table('diagnostic_protocols')
            ->whereIn('health_center_id', function ($query): void {
                $query->select('id')->from('health_centers')->where('operates_in_portal', false);
            })
            ->update([
                'custody_mode' => 'DERIVED',
                'derived_to_health_center_id' => DB::raw('health_center_id'),
                'derived_to_name' => DB::raw(
                    '(SELECT name FROM health_centers WHERE health_centers.id = diagnostic_protocols.health_center_id)'
                ),
            ]);

        // An act with no institution at all has nobody on the other side by definition (Case 8).
        // Its destination stays unknown: nothing in the old model recorded where those tubes went,
        // and inventing one now would be worse than leaving the gap visible.
        DB::table('diagnostic_protocols')->whereNull('health_center_id')->update([
            'custody_mode' => 'DERIVED',
        ]);
    }

    public function down(): void
    {
        Schema::table('diagnostic_protocols', function (Blueprint $table): void {
            $table->dropIndex('dp_company_custody_idx');
            $table->dropConstrainedForeignId('derived_to_health_center_id');
            $table->dropColumn(['custody_mode', 'derived_to_name']);
        });
    }
};
