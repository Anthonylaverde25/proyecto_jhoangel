<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A public OAuth client (an AI product) registered through Dynamic Client Registration.
 */
class McpOAuthClient extends Model
{
    protected $table = 'mcp_oauth_clients';

    /** @var string[] */
    protected $fillable = [
        'client_id',
        'client_name',
        'redirect_uris',
    ];

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
        ];
    }
}
