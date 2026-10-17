<?php

declare(strict_types=1);

namespace App\Application\DTOs\Assistant;

/**
 * One chat turn as received from the drawer, plus the identity it must be answered with.
 */
final readonly class AssistantChatDTO
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function __construct(
        public string $userToken,
        public string $tenantHost,
        public int $companyId,
        public array $messages,
    ) {
    }
}
