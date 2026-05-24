<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Core\Entities\ServiceOrderEntity;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderRepository;

final class ApproveServiceOrderUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $repository
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
            $entity->approve($userId);
        } else {
            $entity->reject($userId, $reason ?? "Rejected during approval");
        }

        return $this->repository->save($entity, $userId, $reason);
    }
}
