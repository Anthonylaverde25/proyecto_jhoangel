<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-33: the professional's credential is born from an invitation, so the producer never knows
 * their password. Only the hash is stored: a database dump never yields a usable invitation,
 * the same rule the portal access tokens already follow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('veterinarian_id')->constrained('veterinarians')->cascadeOnDelete();

            $table->string('email', 150);
            $table->string('token_hash', 64)->unique();

            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'email'], 'ui_company_email_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
