<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single-use OAuth authorization code; only its SHA-256 hash is stored.
 */
class McpOAuthAuthorizationCode extends Model
{
    protected $table = 'mcp_oauth_authorization_codes';

    /** @var string[] */
    protected $fillable = [
        'code_hash',
        'oauth_client_id',
        'user_id',
        'company_id',
        'redirect_uri',
        'code_challenge',
        'scope',
        'resource',
        'expires_at',
        'used_at',
        'mcp_connection_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
