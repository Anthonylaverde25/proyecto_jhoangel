<?php

declare(strict_types=1);

namespace App\Application\UseCases\TransferOrders;

use App\Core\Entities\TransferOrderEntity;
use App\Core\Interfaces\ITransferOrderRepository;

final class ListTransferOrdersUseCase
{
    public function __construct(private readonly ITransferOrderRepository $repository)
    {
    }

    /**
     * @return TransferOrderEntity[]
     */
    public function __invoke(int $companyId, ?string $status = null, ?int $sourceBatchId = null, ?string $kind = null): array
    {
        return $this->repository->list($companyId, $status, $sourceBatchId, $kind);
    }
}
