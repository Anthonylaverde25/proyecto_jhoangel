<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeighingOrders;

use App\Core\Entities\WeighingOrderEntity;
use App\Core\Interfaces\IWeighingOrderRepository;

final class ListBatchWeighingsUseCase
{
    public function __construct(
        private readonly IWeighingOrderRepository $orders,
    ) {
    }

    /** @return WeighingOrderEntity[] */
    public function __invoke(int $batchId, int $companyId): array
    {
        return $this->orders->findByBatch($batchId, $companyId);
    }
}
