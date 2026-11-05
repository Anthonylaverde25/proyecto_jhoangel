<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\ServiceOrders\RegisteredBullStayDTO;
use App\Core\Enums\BatchWeightCause;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Services\BatchWeightService;

/**
 * Leaves on record the trip of a bull through a service registered after the fact: into the batch of
 * the service and out to where it went, on their real days.
 *
 * Those movements speak about the past, so they only change where the bull is today when nothing
 * newer says otherwise: a later movement or an active order wins.
 */
final class RegisteredBullStayRecorder
{
    public function __construct(
        private readonly IServiceOrderRepository $orders,
        private readonly BatchWeightService $batchWeightService
    ) {
    }

    public function record(int $companyId, int $batchId, string $orderCode, RegisteredBullStayDTO $bull): void
    {
        if ($bull->originBatchId !== $batchId) {
            $this->orders->recordBullMovement(
                $bull->maleCaravanId,
                $companyId,
                $bull->originBatchId,
                $batchId,
                $bull->joinedAt,
                "Ingreso de reproductor a servicio (Orden {$orderCode}, registrada después del hecho)"
            );
        }

        $this->orders->recordBullMovement(
            $bull->maleCaravanId,
            $companyId,
            $batchId,
            $bull->destinationBatchId,
            $bull->retiredAt,
            "Salida de reproductor del servicio (Orden {$orderCode}, registrada después del hecho)"
        );

        $location = $this->orders->findBullLocations([$bull->maleCaravanId], $companyId, $bull->retiredAt, $bull->retiredAt)[0] ?? null;
        $isNewer = ($location['moved_after'] ?? false)
            || $this->orders->findActiveOrdersByCaravans([$bull->maleCaravanId], $companyId) !== [];
        if ($isNewer) {
            return;
        }

        $previous = $this->orders->relocateBull($bull->maleCaravanId, $companyId, $bull->destinationBatchId);
        if ($previous === null) {
            return;
        }
        if ($previous > 0) {
            $this->batchWeightService->recalculateBatchWeight($previous, BatchWeightCause::MOVEMENT_OUT);
        }
        $this->batchWeightService->recalculateBatchWeight($bull->destinationBatchId, BatchWeightCause::MOVEMENT_IN);
    }
}
