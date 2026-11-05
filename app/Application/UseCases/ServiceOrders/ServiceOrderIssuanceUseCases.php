<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

/** How a service order comes to exist: started, planned or registered after the fact. */
final readonly class ServiceOrderIssuanceUseCases
{
    public function __construct(
        public IssueServiceOrdersUseCase $issue,
        public RegisterServiceOrdersUseCase $register,
        public StartPlannedServiceOrderUseCase $startPlanned,
        public UpdatePlannedServiceOrderUseCase $updatePlanned
    ) {
    }
}
