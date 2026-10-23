<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One AI client connected to the system on behalf of a user, inside one company.
 *
 * The secret is a Sanctum personal access token restricted to `mcp:read`; this row adds what
 * Sanctum does not know: the company the connection is pinned to, how it was created (a token
 * pasted by hand or an OAuth grant) and, for OAuth, the hash of its current refresh token.
 * Revoking deletes the Sanctum token (the foreign key is nulled) and keeps the row for audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('source', 20); // manual | oauth
            $table->foreignId('oauth_client_id')->nullable()->constrained('mcp_oauth_clients')->nullOnDelete();
            $table->foreignId('personal_access_token_id')
                ->nullable()
                ->unique()
                ->constrained('personal_access_tokens')
                ->nullOnDelete();
            $table->string('refresh_token_hash', 64)->nullable()->unique();
            $table->string('name', 150);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'revoked_at'], 'mcp_connections_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_connections');
    }
};
