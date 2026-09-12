<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Core\Entities\VeterinaryPortalAccessTokenEntity;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Interfaces\IVeterinaryPortalAccessTokenRepository;

final class ListVeterinaryPortalTokensUseCase
{
    public function __construct(
        private readonly IVeterinaryPortalAccessTokenRepository $repository,
        private readonly ICompanyContext $companyContext
    ) {
    }

    /**
     * @return array<VeterinaryPortalAccessTokenEntity>
     */
    public function __invoke(bool $activeOnly = false): array
    {
        return $this->repository->findAll($this->companyContext->getCompanyId() ?? 0, $activeOnly);
    }
}
