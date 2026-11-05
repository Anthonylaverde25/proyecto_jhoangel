<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Application\Services\PlannedBullsReview;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Services\ServiceStartPolicy;

/** "Editar" a planned order: it is an intention until someone starts it. */
final class UpdatePlannedServiceOrderUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $orders,
        private readonly ServiceStartPolicy $startPolicy,
        private readonly PlannedBullsReview $bullsReview
    ) {
    }

    /**
     * @param int[] $maleIds
     * @return array{order: ServiceOrderEntity, warnings: list<string>}
     *
     * @throws ServiceOrderDomainException
     */
    public function __invoke(int $orderId, int $companyId, int $userId, string $startDate, ?string $endDate, ?string $observations, array $maleIds): array
    {
        $order = $this->orders->findById($orderId, $companyId)
            ?? throw ServiceOrderDomainException::domainError('La orden de servicio no existe.');
        if ($order->getStatus() !== ServiceOrderStatus::PLANNED) {
            throw ServiceOrderDomainException::domainError("La orden {$order->getCode()} ya no está planificada: no se puede editar.");
        }

        $day = substr($startDate, 0, 10);
        if ($day < now()->toDateString()) {
            throw ServiceOrderDomainException::domainError('Una orden planificada no puede tener fecha pasada. Si ya empezó, usá "Iniciar".');
        }

        $maleIds = $order->getServiceMethod()->needsBulls() ? array_values(array_unique($maleIds)) : [];
        if ($order->getServiceMethod()->needsBulls()) {
            $this->startPolicy->assertHasBulls($maleIds);
        }
        $warnings = $this->bullsReview->warnings($maleIds, $companyId);

        $this->orders->updatePlanned(
            $orderId,
            $companyId,
            [
                'planned_start_date' => $day,
                'planned_end_date'   => $endDate !== null ? substr($endDate, 0, 10) : null,
                'observations'       => $observations,
                'service_type'       => $order->getServiceMethod()->needsBulls()
                    ? (count($maleIds) === 1 ? 'single' : ($order->getServiceType() === 'single' ? 'multi' : $order->getServiceType()))
                    : $order->getServiceType(),
            ],
            $maleIds,
            $userId
        );

        return [
            'order' => $this->orders->findById($orderId, $companyId)
                ?? throw ServiceOrderDomainException::domainError('Error al recuperar la orden planificada.'),
            'warnings' => $warnings,
        ];
    }
}
