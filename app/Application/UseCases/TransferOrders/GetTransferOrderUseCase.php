<?php

declare(strict_types=1);

namespace App\Application\UseCases\TransferOrders;

use App\Core\Entities\TransferOrderEntity;
use App\Core\Interfaces\ITransferOrderRepository;

final class GetTransferOrderUseCase
{
    public function __construct(private readonly ITransferOrderRepository $repository)
    {
    }

    public function __invoke(int $id, int $companyId): ?TransferOrderEntity
    {
        return $this->repository->findById($id, $companyId);
    }
}
