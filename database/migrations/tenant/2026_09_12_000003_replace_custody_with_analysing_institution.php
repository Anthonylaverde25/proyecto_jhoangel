<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The protocol stops pointing at a catalogue and starts describing who analysed.
 *
 * ADR-29 / §1.3: freezing an institution next to a signature is what let a third party
 * laboratory end up stamped under a professional's licence. A signature attests a PERSON —
 * name, licence, CUIT — and that is all it carries now.
 *
 * ADR-38: both CUITs are frozen here. Reading them live from the professional's file would let
 * a later correction change how an already closed report reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diagnostic_protocols', function (Blueprint $table): void {
            // ADR-35: whose letterhead signs the result. Only meaningful on a LAB_REPORT.
            $table->json('analysing_institution')->nullable()->after('signed_veterinarian_name');

            $table->string('signed_cuit', 11)->nullable()->after('signed_license_number');
            $table->string('signed_billing_cuit', 11)->nullable()->after('signed_cuit');
        });

        Schema::table('diagnostic_protocols', function (Blueprint $table): void {
            $table->dropIndex('dp_company_custody_idx');
            $table->dropConstrainedForeignId('derived_to_health_center_id');
            $table->dropConstrainedForeignId('health_center_id');
            $table->dropColumn([
                'custody_mode',
                'derived_to_name',
                'signed_health_center_name',
                'signed_health_center_code',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('diagnostic_protocols', function (Blueprint $table): void {
            $table->foreignId('health_center_id')->nullable()->constrained('health_centers')->restrictOnDelete();
            $table->enum('custody_mode', ['COUNTERPARTY', 'DERIVED'])->default('COUNTERPARTY');
            $table->foreignId('derived_to_health_center_id')->nullable()->constrained('health_centers')->restrictOnDelete();
            $table->string('derived_to_name', 150)->nullable();
            $table->string('signed_health_center_name', 150)->nullable();
            $table->string('signed_health_center_code', 50)->nullable();
            $table->index(['company_id', 'custody_mode'], 'dp_company_custody_idx');
            $table->dropColumn(['analysing_institution', 'signed_cuit', 'signed_billing_cuit']);
        });
    }
};
