<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Core\Entities\ServiceOrderEntity;
use App\Core\Interfaces\IServiceOrderRepository;

final class GetServiceOrderUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $repository
    ) {
    }

    public function __invoke(int $id, int $companyId): ?ServiceOrderEntity
    {
        return $this->repository->findById($id, $companyId);
    }
}
