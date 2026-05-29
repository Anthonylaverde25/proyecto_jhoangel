<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderRepository;

final class UpdateServiceOrderStatusUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $repository
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

        $entity->changeStatus($newStatus, $userId);
        $savedEntity = $this->repository->save($entity, $userId, 'Status updated via administrative patch');

        // If the new status is APPROVED, physically move the animals
        if ($newStatus === ServiceOrderStatus::APPROVED) {
            $allCaravans = array_merge($entity->getMaleCaravanIds(), $entity->getFemaleCaravanIds());
            if (!empty($allCaravans)) {
                $this->repository->moveAnimalsToBatch($allCaravans, $entity->getBatchId(), $companyId, $userId);
            }
        }

        return $savedEntity;
    }
}
