<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Veterinary\AcceptInvitationUseCase;
use App\Application\UseCases\Veterinary\InviteVeterinarianUseCase;
use App\Core\Interfaces\IUserInvitationRepository;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-33: where a professional's credential is born.
 *
 * The accept endpoints are public on purpose, exactly like the portal's temporary links: the
 * invitation IS the credential, and it is validated server side on every call.
 */
class VeterinarianInvitationController extends Controller
{
    public function store(
        int $id,
        Request $request,
        InviteVeterinarianUseCase $useCase
    ): JsonResponse {
        $validated = $request->validate([
            'email' => ['nullable', 'email', 'max:150'],
        ]);

        $invitation = $useCase(
            $id,
            $validated['email'] ?? null,
            $request->user()?->getAuthIdentifier()
        );

        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return response()->json([
            'data' => [
                'email' => $invitation->getEmail(),
                'expires_at' => $invitation->getExpiresAt()->format('Y-m-d H:i:s'),
                // The one and only moment this link is visible. Only its hash is stored.
                'accept_url' => $frontend . '/invitacion/' . $invitation->getPlainToken(),
            ],
        ], 201);
    }

    /** What the professional sees before choosing a password: who invited them, and to what. */
    public function show(string $token, IUserInvitationRepository $invitations): JsonResponse
    {
        $invitation = $invitations->findByPlainToken($token);

        if ($invitation === null || !$invitation->isUsable()) {
            return response()->json([
                'message' => 'Esta invitación ya fue utilizada o venció. Solicite una nueva al establecimiento.',
            ], 410);
        }

        return response()->json([
            'data' => [
                'email' => $invitation->getEmail(),
                'veterinarian_name' => $invitation->getVeterinarianName(),
                'license_number' => $invitation->getLicenseNumber(),
                'expires_at' => $invitation->getExpiresAt()->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    public function accept(
        string $token,
        Request $request,
        AcceptInvitationUseCase $useCase
    ): JsonResponse {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            // The producer never sees this, and never gets to choose it.
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $useCase($token, (string) ($validated['name'] ?? ''), (string) $validated['password']);

        return response()->json([
            'data' => ['email' => $user->email, 'name' => $user->name],
        ], 200);
    }
}
