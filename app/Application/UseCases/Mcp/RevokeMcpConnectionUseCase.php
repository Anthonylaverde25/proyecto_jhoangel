<?php

declare(strict_types=1);

namespace App\Application\UseCases\Mcp;

use App\Application\Services\McpConnectionRevoker;
use App\Core\Interfaces\IMcpConnectionRepository;
use Illuminate\Support\Facades\DB;

final class RevokeMcpConnectionUseCase
{
    public function __construct(
        private readonly IMcpConnectionRepository $connections,
        private readonly McpConnectionRevoker $revoke,
    ) {
    }

    /** False when the connection does not exist for this user and company (reported as 404). */
    public function __invoke(int $connectionId, int $userId, int $companyId): bool
    {
        $connection = $this->connections->findForOwner($connectionId, $userId, $companyId);
        if ($connection === null) {
            return false;
        }

        DB::transaction(fn () => ($this->revoke)($connection, $userId));

        return true;
    }
}
