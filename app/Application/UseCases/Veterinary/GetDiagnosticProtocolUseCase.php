<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Core\Entities\DiagnosticProtocolEntity;
use App\Core\Exceptions\VeterinaryDomainException;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Interfaces\IDiagnosticProtocolRepository;

final class GetDiagnosticProtocolUseCase
{
    public function __construct(
        private readonly IDiagnosticProtocolRepository $repository,
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function __invoke(int $protocolId): DiagnosticProtocolEntity
    {
        $protocol = $this->repository->findById($protocolId, $this->companyContext->getCompanyId() ?? 0);

        if ($protocol === null) {
            throw VeterinaryDomainException::protocolNotFound($protocolId);
        }

        return $protocol;
    }
}
