<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Veterinary\IssueVeterinaryPortalTokenDTO;
use App\Application\Services\SendVeterinaryPortalAccessService;
use App\Application\UseCases\Veterinary\IssueVeterinaryPortalTokenUseCase;
use App\Application\UseCases\Veterinary\ListVeterinaryPortalDirectoryUseCase;
use App\Application\UseCases\Veterinary\ListVeterinaryPortalTokensUseCase;
use App\Application\UseCases\Veterinary\ReissueVeterinaryPortalTokenUseCase;
use App\Application\UseCases\Veterinary\RevokeVeterinaryPortalTokenUseCase;
use App\Core\Entities\VeterinaryPortalAccessTokenEntity;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Interfaces\IVeterinarianRepository;
use App\Http\Controllers\Controller;
use App\Http\Requests\Veterinary\IssueVeterinaryPortalTokenRequest;
use App\Http\Resources\VeterinaryPortalAccessTokenResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Producer-side administration of the temporary links handed to external professionals.
 */
class VeterinaryPortalTokenController extends Controller
{
    public function index(Request $request, ListVeterinaryPortalTokensUseCase $useCase): AnonymousResourceCollection
    {
        return VeterinaryPortalAccessTokenResource::collection($useCase($request->boolean('active_only')));
    }

    /**
     * The establishment's directory of portals: one row per professional.
     *
     * It lives on this controller because every action a row offers — mint a link, resend it,
     * revoke it — is already here, and splitting the read from the writes would only mean two
     * places to keep in step.
     */
    public function directory(Request $request, ListVeterinaryPortalDirectoryUseCase $useCase): JsonResponse
    {
        return response()->json([
            'data' => $useCase($request->boolean('active_only', true)),
        ]);
    }

    public function store(
        IssueVeterinaryPortalTokenRequest $request,
        IssueVeterinaryPortalTokenUseCase $useCase,
        ICompanyContext $companyContext
    ): JsonResponse {
        $payload = $request->validated();
        $payload['company_id'] = $companyContext->getCompanyId();
        $payload['created_by_user_id'] = $request->user()?->getAuthIdentifier();

        $dto = IssueVeterinaryPortalTokenDTO::fromArray($payload);
        $token = $useCase($dto);

        $delivery = $dto->sendEmail
            ? $this->deliver($token, $dto->recipientEmail, $dto->senderNote)
            : ['sent' => false, 'recipient' => null, 'error' => null];

        // 201 with the plaintext link, which will never be retrievable again — shown even when
        // the mail failed, so the operator can still copy it by hand.
        return (new VeterinaryPortalAccessTokenResource($token))
            ->additional(['meta' => ['email' => $delivery]])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * A lost link cannot be resent: only its hash survives. Reissuing mints a replacement with
     * the same scope and revokes the previous grant, which is the safe reading of "I lost it".
     */
    public function reissue(
        Request $request,
        int $id,
        ReissueVeterinaryPortalTokenUseCase $useCase,
        ICompanyContext $companyContext
    ): JsonResponse {
        $validated = $request->validate([
            'send_email' => ['nullable', 'boolean'],
            'recipient_email' => ['nullable', 'email', 'max:150'],
            'sender_note' => ['nullable', 'string', 'max:500'],
            'ttl_hours' => ['nullable', 'integer', 'min:1'],
        ]);

        $token = $useCase(
            $id,
            (int) $companyContext->getCompanyId(),
            $request->user()?->getAuthIdentifier() !== null ? (int) $request->user()->getAuthIdentifier() : null,
            isset($validated['ttl_hours']) ? (int) $validated['ttl_hours'] : null
        );

        $delivery = ($validated['send_email'] ?? false)
            ? $this->deliver($token, $validated['recipient_email'] ?? null, $validated['sender_note'] ?? null)
            : ['sent' => false, 'recipient' => null, 'error' => null];

        return (new VeterinaryPortalAccessTokenResource($token))
            ->additional(['meta' => ['email' => $delivery]])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Falls back to the professional's own address when the operator did not type one.
     *
     * @return array{sent: bool, recipient: ?string, error: ?string}
     */
    private function deliver(
        VeterinaryPortalAccessTokenEntity $token,
        ?string $recipientEmail,
        ?string $senderNote
    ): array {
        if ($recipientEmail === null || trim($recipientEmail) === '') {
            $veterinarian = app(IVeterinarianRepository::class)
                ->findById($token->getVeterinarianId(), $token->getCompanyId());

            $recipientEmail = $veterinarian?->getEmail();
        }

        return app(SendVeterinaryPortalAccessService::class)($token, $recipientEmail, $senderNote);
    }

    public function destroy(Request $request, int $id, RevokeVeterinaryPortalTokenUseCase $useCase): JsonResponse
    {
        $useCase(
            $id,
            $request->user()?->getAuthIdentifier() !== null ? (int) $request->user()->getAuthIdentifier() : null,
            $request->input('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'Acceso temporal revocado.',
        ]);
    }
}
