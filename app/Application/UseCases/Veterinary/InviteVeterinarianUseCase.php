<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Core\Entities\UserInvitationEntity;
use App\Core\Exceptions\VeterinaryDomainException;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Interfaces\IUserInvitationRepository;
use App\Core\Interfaces\IVeterinarianRepository;
use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * ADR-33: giving a professional a way in, without the producer ever knowing their password.
 *
 * The plaintext token exists only inside this call and in the response it produces; the store
 * keeps its SHA-256 hash, the same rule the portal access links already follow.
 */
final class InviteVeterinarianUseCase
{
    public function __construct(
        private readonly IUserInvitationRepository $invitations,
        private readonly IVeterinarianRepository $veterinarians,
        private readonly ICompanyContext $companyContext
    ) {
    }

    /**
     * @throws VeterinaryDomainException
     */
    public function __invoke(int $veterinarianId, ?string $email, ?int $invitedByUserId): UserInvitationEntity
    {
        $companyId = (int) $this->companyContext->getCompanyId();
        $veterinarian = $this->veterinarians->findById($veterinarianId, $companyId);

        if ($veterinarian === null) {
            throw VeterinaryDomainException::veterinarianNotFound($veterinarianId);
        }

        if (!$veterinarian->isActive()) {
            throw VeterinaryDomainException::domainError(
                'No se puede invitar a un profesional dado de baja.'
            );
        }

        $recipient = trim((string) ($email ?? $veterinarian->getEmail() ?? ''));

        if ($recipient === '') {
            throw VeterinaryDomainException::domainError(
                'Hace falta un correo para invitar al profesional: la invitación viaja por ahí.'
            );
        }

        // One live invitation per professional: a second link would leave two working secrets
        // for the same door, and nobody could say which one somebody used.
        $this->invitations->revokePending($veterinarianId, $companyId);

        $plainToken = Str::random(48);
        $ttlDays = (int) config('livestock.veterinary_portal.invitation_ttl_days', 7);

        return $this->invitations->save(
            new UserInvitationEntity(
                id: null,
                companyId: $companyId,
                veterinarianId: $veterinarianId,
                email: $recipient,
                expiresAt: new DateTimeImmutable("+{$ttlDays} days"),
                veterinarianName: $veterinarian->getName(),
                licenseNumber: $veterinarian->getLicenseNumber(),
                plainToken: $plainToken
            ),
            hash('sha256', $plainToken),
            $invitedByUserId
        );
    }
}
