<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Core\Entities\DiagnosticProtocolEntity;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Interfaces\IDiagnosticProtocolRepository;

final class ListDiagnosticProtocolsUseCase
{
    public function __construct(
        private readonly IDiagnosticProtocolRepository $repository,
        private readonly ICompanyContext $companyContext
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<DiagnosticProtocolEntity>
     */
    public function __invoke(array $filters = []): array
    {
        return $this->repository->findAll($this->companyContext->getCompanyId() ?? 0, $filters);
    }
}
