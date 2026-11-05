<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Core\Entities\ServiceOrderEntity;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Enums\BatchWeightCause;
use App\Core\Services\BatchWeightService;
use Illuminate\Support\Facades\DB;

final class ApproveServiceOrderUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $repository,
        private readonly BatchWeightService $batchWeightService
    ) {
    }

    /**
     * @throws ServiceOrderDomainException
     */
    public function __invoke(int $id, int $companyId, int $userId, bool $approve, ?string $reason = null): ServiceOrderEntity
    {
        $entity = $this->repository->findById($id, $companyId);
        if ($entity === null) {
            throw ServiceOrderDomainException::domainError("Service order not found");
        }

        if ($approve) {
            $unclosed = $this->repository->findUnclosedOrderOnBatch($entity->getBatchId(), $id);
            if ($unclosed !== null) {
                throw ServiceOrderDomainException::domainError(
                    "El lote tiene la orden {$unclosed['code']} sin cerrar: cerrala antes de aprobar {$entity->getCode()}."
                );
            }

            return DB::transaction(function () use ($entity, $companyId, $userId, $reason): ServiceOrderEntity {
                $entity->approve($userId);
                $savedEntity = $this->repository->save($entity, $userId, $reason);

                // Only the bulls enter the serviced batch: the females already are there.
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
                // Only a bull that already lived here changes the herd: he stops being part of it.
                $this->batchWeightService->recalculateServicedBatch($entity->getBatchId(), BatchWeightCause::MOVEMENT_OUT);

                return $savedEntity;
            });
        } else {
            $entity->reject($userId, $reason ?? "Rejected during approval");
            return $this->repository->save($entity, $userId, $reason);
        }
    }
}
