<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\McpOAuthMapper;
use App\Core\Entities\McpAuthorizationCodeEntity;
use App\Core\Interfaces\IMcpAuthorizationCodeRepository;
use App\Models\McpOAuthAuthorizationCode;
use Illuminate\Support\Carbon;

class EloquentMcpAuthorizationCodeRepository implements IMcpAuthorizationCodeRepository
{
    public function save(McpAuthorizationCodeEntity $code, string $codeHash): McpAuthorizationCodeEntity
    {
        $model = McpOAuthAuthorizationCode::query()->create([
            'code_hash' => $codeHash,
            'oauth_client_id' => $code->getOAuthClientId(),
            'user_id' => $code->getUserId(),
            'company_id' => $code->getCompanyId(),
            'redirect_uri' => $code->getRedirectUri(),
            'code_challenge' => $code->getCodeChallenge(),
            'scope' => $code->getScope(),
            'resource' => $code->getResource(),
            'expires_at' => Carbon::instance($code->getExpiresAt()),
        ]);

        return McpOAuthMapper::codeToDomain($model);
    }

    public function findByCodeHash(string $codeHash): ?McpAuthorizationCodeEntity
    {
        $model = McpOAuthAuthorizationCode::query()->where('code_hash', $codeHash)->first();

        return $model !== null ? McpOAuthMapper::codeToDomain($model) : null;
    }

    public function consume(int $codeId): bool
    {
        return McpOAuthAuthorizationCode::query()
            ->whereKey($codeId)
            ->whereNull('used_at')
            ->update(['used_at' => Carbon::now(), 'updated_at' => Carbon::now()]) === 1;
    }

    public function attachConnection(int $codeId, int $connectionId): void
    {
        McpOAuthAuthorizationCode::query()
            ->whereKey($codeId)
            ->update(['mcp_connection_id' => $connectionId]);
    }
}
