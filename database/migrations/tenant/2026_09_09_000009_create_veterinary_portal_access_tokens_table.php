<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Temporary, revocable access grants to the veterinary portal for professionals and health
 * centres that have no account in the system. The producer issues a link, sends it over
 * WhatsApp/email, and the external vet loads results straight into the portal.
 *
 * Only the SHA-256 hash of the secret is persisted: a database dump must not yield working
 * links. `token_prefix` exists solely so the operator can recognise an issued link in a list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('veterinary_portal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('veterinarian_id')->constrained('veterinarians')->onDelete('cascade');
            $table->foreignId('health_center_id')->nullable()->constrained('health_centers')->nullOnDelete();

            // Optional narrowing: when set, the grant is limited to a single batch instead of
            // every batch currently assigned to the professional.
            $table->foreignId('batch_id')->nullable()->constrained('batches')->onDelete('cascade');

            $table->string('token_hash', 64)->unique()->comment('SHA-256 of the plaintext token, never the token itself');
            $table->string('token_prefix', 12)->comment('First characters of the token, for operator recognition only');
            $table->string('label', 150)->nullable()->comment('Human readable purpose, e.g. "Raspajes Lote Recria 3"');

            $table->timestamp('expires_at')->comment('Hard expiry; a link is never open ended');
            $table->unsignedInteger('max_uses')->nullable()->comment('Null means unlimited uses until expiry');
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revoke_reason')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'veterinarian_id', 'revoked_at'], 'vpat_company_vet_active_idx');
            $table->index(['company_id', 'expires_at'], 'vpat_company_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('veterinary_portal_access_tokens');
    }
};
