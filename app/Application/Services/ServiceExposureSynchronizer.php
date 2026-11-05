<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Enums\AnimalSex;
use App\Core\Enums\ServiceExposureExitReason;
use App\Core\Enums\ServiceMethod;
use App\Core\Enums\ServiceOrderIncidentType;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IServiceOrderIncidentRepository;
use App\Core\Interfaces\IServiceOrderRepository;

/**
 * Keeps the exposure window of every female in line with where she physically is.
 *
 * A service runs on a batch, so the exposed females are whoever shares the paddock with the
 * bulls: a female leaving the batch stops being exposed, and one entering a batch under natural
 * service starts being exposed from that day. Controlled service and AI expose only the females
 * the operator picked, so nobody joins them automatically.
 */
final class ServiceExposureSynchronizer
{
    public function __construct(
        private readonly IServiceOrderRepository $serviceOrderRepository,
        private readonly ICaravanRepository $caravanRepository,
        private readonly PendingTactoFemales $pendingTacto,
        private readonly IServiceOrderIncidentRepository $incidents
    ) {
    }

    public function onMovement(int $caravanId, ?int $fromBatchId, ?int $toBatchId, string $movementDate, string $movementType): void
    {
        if ($fromBatchId === $toBatchId) {
            return;
        }

        $date = substr($movementDate, 0, 10);

        // Dead or sold before any verdict: she will never be diagnosed in the orders still open.
        $exitReason = ServiceExposureExitReason::fromMovementType($movementType);
        if ($exitReason === ServiceExposureExitReason::DEATH || $exitReason === ServiceExposureExitReason::SALE) {
            $this->serviceOrderRepository->markExitedWhereUnchecked($caravanId);
        }

        if ($fromBatchId !== null) {
            $leftService = $this->serviceOrderRepository->findActiveServiceOnBatch($fromBatchId);
            if ($leftService !== null && $leftService['status'] === ServiceOrderStatus::APPROVED->value) {
                $this->serviceOrderRepository->closeFemaleExposure(
                    $leftService['id'],
                    $caravanId,
                    $date,
                    ServiceExposureExitReason::fromMovementType($movementType)
                );
            }
        }

        if ($toBatchId === null) {
            return;
        }

        $joinedService = $this->serviceOrderRepository->findActiveServiceOnBatch($toBatchId);
        if ($joinedService === null
            || $joinedService['status'] !== ServiceOrderStatus::APPROVED->value
            || $joinedService['is_controlled_service']
            // An AI order picks its females one by one: a newcomer is not inseminated by being there (IA2).
            || $joinedService['service_method'] === ServiceMethod::AI->value) {
            return;
        }

        $caravan = $this->caravanRepository->findById($caravanId);
        if ($caravan === null || $caravan->getSex() !== AnimalSex::FEMALE || $caravan->hasActiveGestation()) {
            return;
        }

        $companyId = (int) $caravan->getCompanyId();
        $exposedElsewhere = $this->serviceOrderRepository->findActiveOrdersByCaravans([$caravanId], $companyId) !== [];
        if ($exposedElsewhere && !$this->serviceOrderRepository->isFemaleInOrder($joinedService['id'], $caravanId)) {
            return;
        }

        $this->serviceOrderRepository->openFemaleExposure($joinedService['id'], $companyId, $caravanId, $date, true);
        $this->reportPendingTacto($caravanId, $joinedService['id'], $companyId, $date);
    }

    /** Moving her is a fact of the field and is never stopped: it is left for someone to settle. */
    private function reportPendingTacto(int $caravanId, int $joinedOrderId, int $companyId, string $date): void
    {
        $pending = $this->pendingTacto->of([$caravanId], $companyId, $joinedOrderId);
        if ($pending === []) {
            return;
        }

        $this->incidents->raise(
            companyId: $companyId,
            serviceOrderId: $joinedOrderId,
            femaleCaravanId: $caravanId,
            type: ServiceOrderIncidentType::JOINED_WITH_PENDING_TACTO,
            detail: "Entró al lote el " . date('d/m/Y', (int) strtotime($date)) . " con el tacto pendiente: {$this->pendingTacto->describe($pending)}. Quedó expuesta con los toros: tactala o apartala.",
            metadata: ['joined_on' => $date, 'pending_order_id' => $pending[$caravanId]['order_id']],
            raisedByUserId: null
        );
    }
}
