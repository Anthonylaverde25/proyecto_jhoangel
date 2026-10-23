<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\McpAuthorizationCodeEntity;

interface IMcpAuthorizationCodeRepository
{
    public function save(McpAuthorizationCodeEntity $code, string $codeHash): McpAuthorizationCodeEntity;

    public function findByCodeHash(string $codeHash): ?McpAuthorizationCodeEntity;

    /** Marks the code as used; false when another request consumed it first. */
    public function consume(int $codeId): bool;

    public function attachConnection(int $codeId, int $connectionId): void;
}
