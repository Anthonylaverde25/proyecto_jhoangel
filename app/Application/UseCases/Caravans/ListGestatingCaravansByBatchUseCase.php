<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Core\Interfaces\ICaravanRepository;
use App\Core\Entities\CaravanEntity;

final class ListGestatingCaravansByBatchUseCase
{
    public function __construct(
        private readonly ICaravanRepository $repository
    ) {
    }

    /**
     * @param int $batchId
     * @return CaravanEntity[]
     */
    public function __invoke(int $batchId): array
    {
        return $this->repository->findGestatingByBatch($batchId);
    }
}
