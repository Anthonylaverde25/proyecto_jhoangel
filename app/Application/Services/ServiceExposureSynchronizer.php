<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Enums\AnimalSex;
use App\Core\Enums\ServiceExposureExitReason;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Interfaces\ICaravanRepository;
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
        private readonly ICaravanRepository $caravanRepository
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
            || $joinedService['is_controlled_service']) {
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
    }
}
