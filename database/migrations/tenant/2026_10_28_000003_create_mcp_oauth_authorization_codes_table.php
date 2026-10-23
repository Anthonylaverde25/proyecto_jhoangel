<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short-lived OAuth authorization codes, issued when the user approves an AI client.
 *
 * Only the SHA-256 of the code is stored. A code is single use: `used_at` is set when it is
 * exchanged, and `mcp_connection_id` remembers which connection it produced so that a replayed
 * code can revoke it (RFC 6749 §4.1.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_oauth_authorization_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->foreignId('oauth_client_id')->constrained('mcp_oauth_clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('redirect_uri', 2000);
            $table->string('code_challenge', 128);
            $table->string('scope', 100);
            $table->string('resource', 500)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('mcp_connection_id')->nullable()->constrained('mcp_connections')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_oauth_authorization_codes');
    }
};
