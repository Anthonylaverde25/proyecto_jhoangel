<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * An AI client connected through MCP on behalf of a user, pinned to one company.
 *
 * No company global scope on purpose: the token verifier resolves a connection by its Sanctum
 * token before any `X-Company-ID` is known, and every other query filters by owner explicitly.
 */
class McpConnection extends Model
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_OAUTH = 'oauth';

    protected $table = 'mcp_connections';

    /** @var string[] */
    protected $fillable = [
        'user_id',
        'company_id',
        'source',
        'oauth_client_id',
        'personal_access_token_id',
        'refresh_token_hash',
        'name',
        'expires_at',
        'revoked_at',
        'revoked_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }
}
