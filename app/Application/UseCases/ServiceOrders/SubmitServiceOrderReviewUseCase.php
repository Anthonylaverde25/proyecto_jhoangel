<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Core\Entities\ServiceOrderEntity;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderRepository;

final class SubmitServiceOrderReviewUseCase
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

        $entity->submitForReview();

        return $this->repository->save($entity, $userId);
    }
}
