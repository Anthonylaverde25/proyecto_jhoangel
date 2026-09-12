<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-30: the unit is the SHIPMENT, not the reception.
 *
 * `sample_receptions` asked the veterinarian to register an arrival — a fact they did not
 * witness, because they are the one who dispatched. That mismatch is what forced the whole
 * COUNTERPARTY / SELF_DECLARED apparatus of v7. Modelling what the declarant actually saw makes
 * the problem disappear instead of naming it carefully.
 *
 * ADR-36: it hangs off the company, never off an act. One cooler is one row even when it carries
 * tubes from several chute sessions — you pack a box, not an act.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sample_shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');

            $table->date('shipped_on')->comment('The day the tubes physically left the professional');

            // ADR-29: the destination is described right here. No catalogue row backs it, and
            // none is needed: the CUIT inside identifies the institution.
            $table->json('institution');

            $table->boolean('cold_chain_ok');
            $table->text('condition_notes')->nullable()
                ->comment('How they travelled — mandatory when the cold chain broke');

            // ADR-30: whoever declares this witnessed it. Frozen like every other attestation.
            $table->foreignId('declared_by_veterinarian_id')->constrained('veterinarians')->restrictOnDelete();
            $table->string('declared_by_name', 150);
            $table->foreignId('declared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('declared_at');

            // ADR-37: a shipment already cited by a report is never edited, only voided.
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'shipped_on'], 'ss_company_date_idx');
            $table->index(['company_id', 'declared_by_veterinarian_id'], 'ss_company_vet_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_shipments');
    }
};
