<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderInseminationRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Enums\BatchWeightCause;
use App\Core\Services\BatchWeightService;
use Illuminate\Support\Facades\DB;

final class UpdateServiceOrderStatusUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $repository,
        private readonly BatchWeightService $batchWeightService,
        private readonly IServiceOrderInseminationRepository $inseminations
    ) {
    }

    /**
     * @throws ServiceOrderDomainException
     */
    public function __invoke(int $id, int $companyId, int $userId, ServiceOrderStatus $newStatus): ServiceOrderEntity
    {
        $entity = $this->repository->findById($id, $companyId);
        if ($entity === null) {
            throw ServiceOrderDomainException::domainError("Service order not found");
        }

        $wasApproved = $entity->getStatus() === ServiceOrderStatus::APPROVED;

        return DB::transaction(function () use ($entity, $companyId, $userId, $newStatus, $wasApproved): ServiceOrderEntity {
            $liveInseminations = $entity->isInsemination() ? $this->inseminations->liveCountByOrder((int) $entity->getId()) : 0;
            $entity->changeStatus($newStatus, $userId, $liveInseminations);
            $savedEntity = $this->repository->save($entity, $userId, 'Status updated via administrative patch');

            // On approval only the bulls enter the serviced batch: the females already are there.
            if ($newStatus === ServiceOrderStatus::APPROVED && !$wasApproved) {
                $donorBatchIds = $this->repository->moveBullsToBatch(
                    $entity->getMaleCaravanIds(),
                    $entity->getBatchId(),
                    $companyId,
                    (string) $entity->getActualStartDate(),
                    $entity->getCode()
                );
                foreach ($donorBatchIds as $donorBatchId) {
                    $this->batchWeightService->recalculateBatchWeight($donorBatchId, BatchWeightCause::MOVEMENT_OUT);
                }
                if ($donorBatchIds !== []) {
                    $this->batchWeightService->recalculateBatchWeight($entity->getBatchId(), BatchWeightCause::MOVEMENT_IN);
                }
            }

            return $savedEntity;
        });
    }
}
