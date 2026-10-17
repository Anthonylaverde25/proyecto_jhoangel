<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Assistant\AssistantChatDTO;
use App\Application\UseCases\Assistant\AskAssistantUseCase;
use App\Core\Exceptions\AssistantUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssistantChatRequest;
use Illuminate\Http\JsonResponse;

/**
 * The web drawer's Virtual Assistant. Sits behind auth:sanctum, so by the time a request gets here
 * the token is valid and CompanyContextMiddleware has checked the user belongs to X-Company-ID.
 */
final class AssistantController extends Controller
{
    public function __construct(
        private readonly AskAssistantUseCase $ask,
    ) {
    }

    public function chat(AssistantChatRequest $request): JsonResponse
    {
        $messages = array_map(
            fn (array $message): array => ['role' => (string) $message['role'], 'content' => (string) $message['content']],
            $request->validated('messages')
        );

        try {
            $answer = ($this->ask)(new AssistantChatDTO(
                userToken: (string) $request->bearerToken(),
                tenantHost: $request->getHttpHost(),
                companyId: (int) $request->header('X-Company-ID'),
                messages: $messages,
            ));
        } catch (AssistantUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($answer);
    }
}
