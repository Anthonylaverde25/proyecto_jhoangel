<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Core\Entities\ServiceOrderEntity;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderRepository;

final class CompleteServiceOrderUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $repository
    ) {
    }

    /**
     * @throws ServiceOrderDomainException
     */
    public function __invoke(int $id, int $companyId, int $userId, ?string $observations = null): ServiceOrderEntity
    {
        $entity = $this->repository->findById($id, $companyId);
        if ($entity === null) {
            throw ServiceOrderDomainException::domainError("Service order not found");
        }

        $entity->complete($observations);

        return $this->repository->save($entity, $userId);
    }
}
