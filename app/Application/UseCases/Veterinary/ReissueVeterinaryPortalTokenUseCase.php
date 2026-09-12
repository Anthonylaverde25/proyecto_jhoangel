<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Application\DTOs\Veterinary\IssueVeterinaryPortalTokenDTO;
use App\Core\Entities\VeterinaryPortalAccessTokenEntity;
use App\Core\Exceptions\VeterinaryDomainException;
use App\Core\Interfaces\IVeterinaryPortalAccessTokenRepository;
use Illuminate\Support\Carbon;

/**
 * "The vet asked for the link again."
 *
 * There is no resend: only the SHA-256 of the token is stored, so the original link is
 * unrecoverable by design. The honest answer to a lost link is a NEW one — and the old grant is
 * revoked in the same breath, because a link that was mislaid is a link that may be in the wrong
 * hands.
 */
final class ReissueVeterinaryPortalTokenUseCase
{
    public function __construct(
        private readonly IVeterinaryPortalAccessTokenRepository $tokenRepository,
        private readonly IssueVeterinaryPortalTokenUseCase $issueToken
    ) {
    }

    /**
     * @throws VeterinaryDomainException
     */
    public function __invoke(
        int $tokenId,
        int $companyId,
        ?int $actorUserId,
        ?int $ttlHours = null
    ): VeterinaryPortalAccessTokenEntity {
        $previous = $this->tokenRepository->findById($tokenId, $companyId);

        if ($previous === null) {
            throw VeterinaryDomainException::domainError('El acceso temporal indicado no existe.');
        }

        // Same professional, same scope, same label: what changes is the secret.
        $hoursLeft = $ttlHours ?? $this->remainingHours($previous);

        $replacement = $this->issueToken->__invoke(new IssueVeterinaryPortalTokenDTO(
            companyId: $companyId,
            veterinarianId: $previous->getVeterinarianId(),
            ttlHours: $hoursLeft,
            batchId: $previous->getBatchId(),
            diagnosticProtocolId: $previous->getDiagnosticProtocolId(),
            label: $previous->getLabel(),
            maxUses: $previous->getMaxUses(),
            createdByUserId: $actorUserId
        ));

        if (!$previous->isRevoked()) {
            $this->tokenRepository->revoke(
                $tokenId,
                $companyId,
                $actorUserId,
                'Reemitido: se generó un enlace nuevo a pedido del profesional.'
            );
        }

        return $replacement;
    }

    /**
     * Keeps the original expiry window when it is still in the future, so reissuing does not
     * silently extend an access someone deliberately time boxed.
     */
    private function remainingHours(VeterinaryPortalAccessTokenEntity $previous): int
    {
        $default = (int) config('livestock.veterinary_portal.default_ttl_hours', 72);
        $hours = (int) ceil(Carbon::now()->diffInMinutes(Carbon::parse($previous->getExpiresAt()->format('Y-m-d H:i:s')), false) / 60);

        return $hours > 0 ? $hours : $default;
    }
}
