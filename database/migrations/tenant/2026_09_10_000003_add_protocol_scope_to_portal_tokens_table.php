<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-16: the temporary link stops being "the whole batch, no expiry" and becomes a grant
 * over one extraction act. The link is sent to a laboratory and opened by whoever is on duty,
 * so a permanent grant forwarded through WhatsApp is an open key to the establishment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('veterinary_portal_access_tokens', function (Blueprint $table) {
            $table->foreignId('diagnostic_protocol_id')
                ->nullable()
                ->after('batch_id')
                ->constrained('diagnostic_protocols')
                ->onDelete('cascade')
                ->comment('Narrows the grant to a single extraction act');

            $table->index(['company_id', 'diagnostic_protocol_id'], 'vpat_company_protocol_idx');
        });
    }

    public function down(): void
    {
        Schema::table('veterinary_portal_access_tokens', function (Blueprint $table) {
            $table->dropIndex('vpat_company_protocol_idx');
            $table->dropConstrainedForeignId('diagnostic_protocol_id');
        });
    }
};
