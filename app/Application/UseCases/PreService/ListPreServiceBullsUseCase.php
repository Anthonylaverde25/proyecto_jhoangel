<?php

declare(strict_types=1);

namespace App\Application\UseCases\PreService;

use App\Core\Entities\BullHealthEvaluationEntity;
use App\Core\Interfaces\IBullHealthEvaluationRepository;
use App\Core\Interfaces\ICompanyContext;

final class ListPreServiceBullsUseCase
{
    public function __construct(
        private readonly IBullHealthEvaluationRepository $bullHealthRepository,
        private readonly ICompanyContext $companyContext
    ) {
    }

    /**
     * @return array<BullHealthEvaluationEntity>
     */
    public function __invoke(): array
    {
        $companyId = $this->companyContext->getCompanyId() ?? 1;

        return $this->bullHealthRepository->findAllBullsWithHealth($companyId);
    }
}
