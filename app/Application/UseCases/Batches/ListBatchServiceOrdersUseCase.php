<?php

declare(strict_types=1);

namespace App\Application\UseCases\Batches;

use App\Core\Entities\ServiceOrderEntity;
use App\Core\Interfaces\IServiceOrderRepository;

/**
 * Service history of a batch: every service the batch went through, newest first.
 */
final class ListBatchServiceOrdersUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $serviceOrderRepository
    ) {
    }

    /**
     * @return ServiceOrderEntity[]
     */
    public function __invoke(int $batchId, int $companyId): array
    {
        return $this->serviceOrderRepository->findByBatch($batchId, $companyId);
    }
}
