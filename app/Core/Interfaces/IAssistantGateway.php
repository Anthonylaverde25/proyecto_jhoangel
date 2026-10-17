<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Exceptions\AssistantUnavailableException;

/**
 * The conversational assistant, reached on behalf of the user who asks.
 *
 * The identity travels with every call so the assistant reads the system exactly as that user
 * would: same token, same tenant (by host), same company.
 */
interface IAssistantGateway
{
    /**
     * @param array<int, array{role: string, content: string}> $messages Previous turns plus the question, oldest first.
     * @return array<string, mixed> The assistant response: blocks, tools_used, grounded.
     *
     * @throws AssistantUnavailableException
     */
    public function ask(string $userToken, string $tenantHost, int $companyId, array $messages): array;
}
