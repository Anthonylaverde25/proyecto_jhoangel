<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeaningOrders;

use App\Core\Entities\WeaningOrderEntity;
use App\Core\Interfaces\IWeaningOrderRepository;

final class GetWeaningOrderUseCase
{
    public function __construct(private readonly IWeaningOrderRepository $repository)
    {
    }

    public function __invoke(int $id, int $companyId): ?WeaningOrderEntity
    {
        return $this->repository->findById($id, $companyId);
    }
}
