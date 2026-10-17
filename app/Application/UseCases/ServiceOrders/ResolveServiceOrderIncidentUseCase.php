<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Core\Enums\ServiceOrderIncidentStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderIncidentRepository;

/**
 * Settles an open incident of a service order with a note. A resolved one is never reopened.
 */
final class ResolveServiceOrderIncidentUseCase
{
    public function __construct(
        private readonly IServiceOrderIncidentRepository $incidentRepository
    ) {
    }

    /**
     * @throws ServiceOrderDomainException
     */
    public function __invoke(int $serviceOrderId, int $incidentId, int $companyId, string $resolution, ?int $userId): void
    {
        $incident = $this->incidentRepository->find($incidentId, $companyId);
        if ($incident === null || $incident['service_order_id'] !== $serviceOrderId) {
            throw ServiceOrderDomainException::domainError('La novedad no existe en esta orden.');
        }

        if ($incident['status'] !== ServiceOrderIncidentStatus::OPEN->value) {
            throw ServiceOrderDomainException::domainError('La novedad ya está resuelta.');
        }

        if (trim($resolution) === '') {
            throw ServiceOrderDomainException::domainError('Escribí cómo se resolvió la novedad.');
        }

        $this->incidentRepository->resolve($incidentId, trim($resolution), $userId);
    }
}
