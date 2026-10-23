<?php

declare(strict_types=1);

namespace App\Application\UseCases\Mcp;

use App\Application\DTOs\Mcp\IssueMcpConnectionDTO;
use App\Core\Entities\McpConnectionEntity;
use App\Core\Exceptions\McpConnectionException;
use App\Core\Interfaces\IMcpConnectionRepository;
use App\Core\Interfaces\IMcpTokenGateway;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Connects an AI client by hand: mints a read-only token the user pastes into the client
 * (Gemini CLI, Claude Code, any client that accepts an Authorization header).
 */
final class IssueMcpConnectionUseCase
{
    public function __construct(
        private readonly IMcpConnectionRepository $connections,
        private readonly IMcpTokenGateway $tokens,
    ) {
    }

    /**
     * @return array{0: McpConnectionEntity, 1: string} the connection and its plaintext token, shown once
     *
     * @throws McpConnectionException
     */
    public function __invoke(IssueMcpConnectionDTO $dto): array
    {
        if ($dto->companyId <= 0) {
            throw McpConnectionException::companyRequired();
        }

        $maxTtl = (int) config('livestock.mcp.max_ttl_days', 180);
        if ($dto->ttlDays < 1 || $dto->ttlDays > $maxTtl) {
            throw McpConnectionException::invalidTtl($maxTtl);
        }

        $maxActive = (int) config('livestock.mcp.max_active_per_user', 5);
        if ($this->connections->countActiveManualForOwner($dto->userId, $dto->companyId) >= $maxActive) {
            throw McpConnectionException::tooManyActive($maxActive);
        }

        $expiresAt = (new DateTimeImmutable())->modify("+{$dto->ttlDays} days");

        return DB::transaction(function () use ($dto, $expiresAt): array {
            $issued = $this->tokens->issue($dto->userId, $dto->name, $expiresAt);
            $connection = $this->connections->save(McpConnectionEntity::manual(
                userId: $dto->userId,
                companyId: $dto->companyId,
                name: $dto->name,
                expiresAt: $expiresAt,
                tokenId: $issued->personalAccessTokenId,
            ));

            return [$connection, $issued->plainTextToken];
        });
    }
}
