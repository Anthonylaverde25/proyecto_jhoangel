<?php

declare(strict_types=1);

namespace App\Application\UseCases\Assistant;

use App\Application\DTOs\Assistant\AssistantChatDTO;
use App\Core\Interfaces\IAssistantGateway;

/**
 * Forwards a chat turn to the assistant acting as the user. Read-only by design: the assistant's
 * tools only query the API, so nothing here can change records.
 */
final class AskAssistantUseCase
{
    public function __construct(
        private readonly IAssistantGateway $assistant,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(AssistantChatDTO $chat): array
    {
        return $this->assistant->ask($chat->userToken, $chat->tenantHost, $chat->companyId, $chat->messages);
    }
}
