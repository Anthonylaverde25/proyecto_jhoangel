<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Core\Entities\ServiceOrderEntity;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderRepository;

final class ExecuteServiceOrderUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $repository
    ) {
    }

    /**
     * @throws ServiceOrderDomainException
     */
    public function __invoke(int $id, int $companyId, int $userId): ServiceOrderEntity
    {
        $entity = $this->repository->findById($id, $companyId);
        if ($entity === null) {
            throw ServiceOrderDomainException::domainError("Service order not found");
        }

        // 1. Transition the domain state to IN_PROGRESS
        $entity->execute();

        // 2. Persist the state change
        $savedEntity = $this->repository->save($entity, $userId);

        // 3. Move all males and females to the destination batch
        $allCaravans = array_merge($entity->getMaleCaravanIds(), $entity->getFemaleCaravanIds());
        if (!empty($allCaravans)) {
            $this->repository->moveAnimalsToBatch($allCaravans, $entity->getBatchId(), $companyId, $userId);
        }

        return $savedEntity;
    }
}
