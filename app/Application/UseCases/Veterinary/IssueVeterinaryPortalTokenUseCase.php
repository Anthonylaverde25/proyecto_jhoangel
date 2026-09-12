<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Application\DTOs\Veterinary\IssueVeterinaryPortalTokenDTO;
use App\Core\Entities\VeterinaryPortalAccessTokenEntity;
use App\Core\Exceptions\VeterinaryDomainException;
use App\Core\Interfaces\IDiagnosticProtocolRepository;
use App\Core\Interfaces\IVeterinarianRepository;
use App\Core\Interfaces\IVeterinaryPortalAccessTokenRepository;
use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * Mints the temporary link the producer hands to an external professional or health centre.
 *
 * The plaintext secret exists only inside this call and in the response it produces: the
 * database keeps its SHA-256 hash, so a dump never yields a working link.
 */
final class IssueVeterinaryPortalTokenUseCase
{
    public function __construct(
        private readonly IVeterinaryPortalAccessTokenRepository $tokenRepository,
        private readonly IVeterinarianRepository $veterinarianRepository,
        private readonly IDiagnosticProtocolRepository $protocolRepository
    ) {
    }

    /**
     * @throws VeterinaryDomainException
     */
    public function __invoke(IssueVeterinaryPortalTokenDTO $dto): VeterinaryPortalAccessTokenEntity
    {
        $veterinarian = $this->veterinarianRepository->findById($dto->veterinarianId, $dto->companyId);

        if ($veterinarian === null) {
            throw VeterinaryDomainException::veterinarianNotFound($dto->veterinarianId);
        }

        if (!$veterinarian->isActive()) {
            throw VeterinaryDomainException::domainError(
                'No se puede emitir un acceso temporal para un profesional dado de baja.'
            );
        }

        $maxTtl = (int) config('livestock.veterinary_portal.max_ttl_hours', 720);

        if ($dto->ttlHours < 1 || $dto->ttlHours > $maxTtl) {
            throw VeterinaryDomainException::domainError(
                "La vigencia del acceso debe estar entre 1 y {$maxTtl} horas."
            );
        }

        // ADR-16: an act scoped grant must actually name an act of this professional, in this
        // company. Otherwise the link would either reach nothing or reach somebody else's work.
        if ($dto->diagnosticProtocolId !== null) {
            $act = $this->protocolRepository->findById($dto->diagnosticProtocolId, $dto->companyId);

            if ($act === null) {
                throw VeterinaryDomainException::actNotFound($dto->diagnosticProtocolId);
            }

            if (!$act->isExtractionAct()) {
                throw VeterinaryDomainException::domainError(
                    'El acceso temporal sólo puede acotarse a un acta de extracción.'
                );
            }

            if ($act->getVeterinarianId() !== $dto->veterinarianId) {
                throw VeterinaryDomainException::domainError(
                    'El acta pertenece a otro profesional: no puede emitirse un acceso a su nombre.'
                );
            }
        } elseif ($dto->batchId === null
            && $this->veterinarianRepository->findActiveBatchIds($dto->veterinarianId, $dto->companyId) === []
            && $this->protocolRepository->findBatchIdsWithActsFor($dto->veterinarianId, $dto->companyId) === []
        ) {
            // A link that reaches nothing is a support ticket waiting to happen.
            throw VeterinaryDomainException::domainError(
                'El profesional no tiene lotes asignados ni actas propias: asigne una tropa o emita el acceso para un acta puntual.'
            );
        }

        $plainToken = $this->generateToken();

        $entity = new VeterinaryPortalAccessTokenEntity(
            id: null,
            companyId: $dto->companyId,
            veterinarianId: $dto->veterinarianId,
            tokenHash: hash('sha256', $plainToken),
            tokenPrefix: substr($plainToken, 0, 8),
            expiresAt: new DateTimeImmutable(sprintf('+%d hours', $dto->ttlHours)),
            batchId: $dto->batchId,
            diagnosticProtocolId: $dto->diagnosticProtocolId,
            label: $dto->label,
            maxUses: $dto->maxUses,
            createdByUserId: $dto->createdByUserId,
            plainToken: $plainToken
        );

        return $this->tokenRepository->save($entity);
    }

    private function generateToken(): string
    {
        $bytes = (int) config('livestock.veterinary_portal.token_bytes', 32);

        return Str::lower(bin2hex(random_bytes(max(16, $bytes))));
    }
}
