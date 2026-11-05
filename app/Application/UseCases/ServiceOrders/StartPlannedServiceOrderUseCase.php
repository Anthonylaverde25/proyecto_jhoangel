<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Application\DTOs\ServiceOrders\StartBatchServiceOrderDTO;
use App\Application\Services\PendingTactoFemales;
use App\Application\UseCases\Batches\StartBatchServiceOrderUseCase;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IServiceOrderRepository;

/**
 * "Iniciar" a planned order, on the day it really starts. Every rule of a new service applies again:
 * since it was planned, a cow may have been sold or a bull fallen ill.
 */
final class StartPlannedServiceOrderUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $orders,
        private readonly ICaravanRepository $caravans,
        private readonly StartBatchServiceOrderUseCase $startService,
        private readonly PendingTactoFemales $pendingTacto
    ) {
    }

    /**
     * @return array{order: ServiceOrderEntity, warnings: list<string>}
     *
     * @throws ServiceOrderDomainException
     */
    public function __invoke(int $orderId, int $companyId, int $userId, string $startDate): array
    {
        $order = $this->orders->findById($orderId, $companyId)
            ?? throw ServiceOrderDomainException::domainError('La orden de servicio no existe.');
        if ($order->getStatus() !== ServiceOrderStatus::PLANNED) {
            throw ServiceOrderDomainException::domainError("La orden {$order->getCode()} no está planificada.");
        }

        $day = substr($startDate, 0, 10);
        if ($day > now()->toDateString()) {
            throw ServiceOrderDomainException::domainError('La fecha real de inicio no puede ser futura.');
        }

        $individually = $order->isControlledService() || $order->getServiceMethod()->picksFemalesIndividually();
        $picked = $individually ? $this->stillServiceable($order) : null;
        $dropped = $individually ? count($order->getFemaleCaravanIds()) - count($picked) : 0;

        $started = ($this->startService)(new StartBatchServiceOrderDTO(
            batchId: $order->getBatchId(),
            companyId: $companyId,
            userId: $userId,
            maleCaravanIds: $order->getServiceMethod()->needsBulls() ? $order->getMaleCaravanIds() : [],
            selectedFemaleCaravanIds: $picked,
            plannedStartDate: $day,
            plannedEndDate: $order->getPlannedEndDate(),
            serviceType: $order->getServiceType(),
            isControlledService: $order->isControlledService(),
            observations: $order->getObservations(),
            serviceMethod: $order->getServiceMethod(),
            plannedSemen: $this->plannedSemen($order, $picked ?? []),
            plannedOrderId: $orderId
        ));

        $warnings = $dropped > 0 ? ["{$dropped} vientre(s) planificado(s) ya no están disponibles o tienen el tacto pendiente en otra orden, y quedaron afuera."] : [];
        $pending = $this->pendingTacto->of($started->getFemaleCaravanIds(), $companyId, $started->getId());
        if ($pending !== []) {
            $warnings[] = "Quedaron expuestas con el tacto pendiente en otra orden: {$this->pendingTacto->describe($pending)}. Tactalas o apartalas.";
        }

        return ['order' => $started, 'warnings' => $warnings];
    }

    /**
     * The females picked when planning that can still be served.
     *
     * @return int[]
     */
    private function stillServiceable(ServiceOrderEntity $order): array
    {
        $serviceable = $this->caravans->findServiceableFemaleIdsInBatch($order->getBatchId(), true);
        $picked = array_values(array_diff(
            array_intersect($order->getFemaleCaravanIds(), $serviceable),
            array_keys($this->pendingTacto->of($order->getFemaleCaravanIds(), $order->getCompanyId()))
        ));
        if ($picked === []) {
            throw ServiceOrderDomainException::domainError("Ninguno de los vientres planificados en {$order->getCode()} sigue disponible en el lote.");
        }

        return $picked;
    }

    /**
     * @param int[] $femaleIds
     * @return array<int, int>
     */
    private function plannedSemen(ServiceOrderEntity $order, array $femaleIds): array
    {
        $planned = [];
        foreach ($order->getFemaleExposures() as $female) {
            $itemId = $female['planned_semen_item_id'] ?? null;
            if ($itemId !== null && in_array((int) $female['female_caravan_id'], $femaleIds, true)) {
                $planned[(int) $female['female_caravan_id']] = (int) $itemId;
            }
        }

        return $planned;
    }
}
