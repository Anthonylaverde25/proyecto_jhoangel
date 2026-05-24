<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Core\Interfaces\IServiceOrderRepository;

final class ListServiceOrdersUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $repository
    ) {
    }

    /**
     * @return \App\Core\Entities\ServiceOrderEntity[]
     */
    public function __invoke(int $companyId): array
    {
        return $this->repository->listAll($companyId);
    }
}
