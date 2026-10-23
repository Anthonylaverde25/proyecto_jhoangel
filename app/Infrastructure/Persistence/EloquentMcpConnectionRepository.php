<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\McpConnectionMapper;
use App\Core\Entities\McpConnectionEntity;
use App\Core\Interfaces\IMcpConnectionRepository;
use App\Models\McpConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class EloquentMcpConnectionRepository implements IMcpConnectionRepository
{
    public function findAllForOwner(int $userId, int $companyId): array
    {
        return McpConnection::query()
            ->with('accessToken')
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (McpConnection $model): McpConnectionEntity => McpConnectionMapper::toDomain($model))
            ->all();
    }

    public function findForOwner(int $connectionId, int $userId, int $companyId): ?McpConnectionEntity
    {
        $model = McpConnection::query()
            ->whereKey($connectionId)
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->first();

        return $model !== null ? McpConnectionMapper::toDomain($model) : null;
    }

    public function findById(int $connectionId): ?McpConnectionEntity
    {
        $model = McpConnection::query()->find($connectionId);

        return $model !== null ? McpConnectionMapper::toDomain($model) : null;
    }

    public function findActiveByAccessTokenId(int $personalAccessTokenId): ?McpConnectionEntity
    {
        $model = $this->active()
            ->with(['user', 'company', 'accessToken'])
            ->where('personal_access_token_id', $personalAccessTokenId)
            ->first();

        return $model !== null ? McpConnectionMapper::toDomain($model) : null;
    }

    public function findActiveByRefreshTokenHash(string $refreshTokenHash): ?McpConnectionEntity
    {
        $model = $this->active()->where('refresh_token_hash', $refreshTokenHash)->first();

        return $model !== null ? McpConnectionMapper::toDomain($model) : null;
    }

    public function findActiveOAuthForClient(int $userId, int $companyId, int $oauthClientId): array
    {
        return $this->active()
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->where('source', McpConnection::SOURCE_OAUTH)
            ->where('oauth_client_id', $oauthClientId)
            ->get()
            ->map(fn (McpConnection $model): McpConnectionEntity => McpConnectionMapper::toDomain($model))
            ->all();
    }

    public function countActiveManualForOwner(int $userId, int $companyId): int
    {
        return $this->active()
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->where('source', McpConnection::SOURCE_MANUAL)
            ->count();
    }

    public function save(McpConnectionEntity $connection, ?string $refreshTokenHash = null): McpConnectionEntity
    {
        $model = McpConnection::query()->create([
            'user_id' => $connection->getUserId(),
            'company_id' => $connection->getCompanyId(),
            'source' => $connection->getSource(),
            'oauth_client_id' => $connection->getOAuthClientId(),
            'personal_access_token_id' => $connection->getPersonalAccessTokenId(),
            'refresh_token_hash' => $refreshTokenHash,
            'name' => $connection->getName(),
            'expires_at' => Carbon::instance($connection->getExpiresAt()),
        ]);

        return McpConnectionMapper::toDomain($model->load('accessToken'));
    }

    public function rotateCredentials(
        int $connectionId,
        string $expectedRefreshTokenHash,
        int $personalAccessTokenId,
        string $refreshTokenHash
    ): bool {
        return McpConnection::query()
            ->whereKey($connectionId)
            ->where('refresh_token_hash', $expectedRefreshTokenHash)
            ->whereNull('revoked_at')
            ->update([
                'personal_access_token_id' => $personalAccessTokenId,
                'refresh_token_hash' => $refreshTokenHash,
                'updated_at' => Carbon::now(),
            ]) === 1;
    }

    public function markRevoked(int $connectionId, ?int $revokedByUserId): void
    {
        McpConnection::query()
            ->whereKey($connectionId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => Carbon::now(),
                'revoked_by_user_id' => $revokedByUserId,
                'personal_access_token_id' => null,
                'refresh_token_hash' => null,
                'updated_at' => Carbon::now(),
            ]);
    }

    /** @return Builder<McpConnection> */
    private function active(): Builder
    {
        return McpConnection::query()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', Carbon::now());
    }
}
