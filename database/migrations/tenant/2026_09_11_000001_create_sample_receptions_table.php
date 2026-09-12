<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-20: the arrival of the samples is an event of its own.
 *
 * Until now a tube went from "drawn" to "waiting for results" without anybody ever declaring
 * having received it. That gap is where a tube gets lost leaving no documentary trace.
 *
 * A table rather than a couple of columns on the act (ADR-20 / Case 9): one act may cover many
 * chute days and be handed over in several trips. A partial delivery does not fit in columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sample_receptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('extraction_act_id')->constrained('diagnostic_protocols')->cascadeOnDelete();

            // ADR-25: a self-declared delivery is not a reception, and the record must not blur them.
            $table->enum('reception_mode', ['COUNTERPARTY', 'SELF_DECLARED']);

            $table->date('received_on')->comment('Physical date of arrival, may differ from registration');
            $table->timestamp('registered_at');

            $table->foreignId('registered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // ADR-8 style snapshot: who took custody, frozen at registration time.
            $table->string('registered_by_name', 150);

            // The institution that took physical custody. In DERIVED mode this is the external lab.
            $table->foreignId('health_center_id')->nullable()->constrained('health_centers')->restrictOnDelete();
            $table->string('health_center_name', 150)->nullable()->comment('Frozen copy at registration time');

            $table->boolean('cold_chain_ok');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'extraction_act_id'], 'sr_company_act_idx');
            $table->index(['company_id', 'received_on'], 'sr_company_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_receptions');
    }
};
