<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Core\Exceptions\VeterinaryDomainException;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Interfaces\IVeterinaryPortalAccessTokenRepository;

final class RevokeVeterinaryPortalTokenUseCase
{
    public function __construct(
        private readonly IVeterinaryPortalAccessTokenRepository $repository,
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function __invoke(int $tokenId, ?int $revokedByUserId, ?string $reason = null): bool
    {
        $revoked = $this->repository->revoke(
            $tokenId,
            $this->companyContext->getCompanyId() ?? 0,
            $revokedByUserId,
            $reason
        );

        if (!$revoked) {
            throw VeterinaryDomainException::domainError(
                "El acceso temporal ID {$tokenId} no existe o ya estaba revocado."
            );
        }

        return true;
    }
}
