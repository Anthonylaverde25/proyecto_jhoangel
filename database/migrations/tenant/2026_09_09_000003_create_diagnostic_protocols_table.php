<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The evidentiary document backing a diagnostic session ("Protocolo LAB-2026-8492").
 * Parent entity of every laboratory determination stored in `bull_lab_samples`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_protocols', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('protocol_number', 100)->comment('Reference number printed on the lab report');
            $table->foreignId('veterinarian_id')->nullable()->constrained('veterinarians')->restrictOnDelete();
            $table->foreignId('health_center_id')->nullable()->constrained('health_centers')->restrictOnDelete();
            $table->date('sample_date')->comment('Date samples were taken at the chute');
            $table->date('result_date')->comment('Official report issue date');
            $table->enum('source_channel', ['PORTAL_VET', 'OWNER_DIGITIZED']);

            // ADR-9: lifecycle. A CONFIRMED protocol is immutable; corrections require VOIDED + reissue.
            $table->enum('status', ['DRAFT', 'CONFIRMED', 'VOIDED'])->default('DRAFT');

            // ADR-9: OWNER_DIGITIZED data is transcribed by hand and must not weigh the same as a signed one.
            $table->enum('verification_status', ['UNVERIFIED', 'VERIFIED'])->default('UNVERIFIED');

            // ADR-8: immutable signature snapshot. Editing the veterinarians catalogue later
            // must NOT retroactively mutate historical protocols.
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('signed_by_veterinarian_id')->nullable()->constrained('veterinarians')->restrictOnDelete();
            $table->string('signed_license_number', 50)->nullable()->comment('Frozen copy of the M.P. at signing time');
            $table->string('signed_veterinarian_name', 150)->nullable()->comment('Frozen copy of the professional name');

            $table->text('observations')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Null when the protocol was filed through a temporary portal token');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();

            $table->timestamps();

            // F9: blocks double ingestion of the same physical report.
            $table->unique(['company_id', 'protocol_number'], 'dp_company_protocol_unq');
            $table->index(['company_id', 'status', 'result_date'], 'dp_company_status_date_idx');
            $table->index(['company_id', 'veterinarian_id'], 'dp_company_vet_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_protocols');
    }
};
