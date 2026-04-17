<?php

declare(strict_types=1);

namespace App\Application\UseCases\Batches;

use App\Core\Interfaces\IBatchRepository;
use App\Core\Entities\BatchEntity;

final class ListBatchesUseCase
{
    public function __construct(
        private readonly IBatchRepository $repository
    ) {
    }

    /**
     * @return BatchEntity[]
     */
    public function __invoke(?int $farmId = null): array
    {
        if ($farmId !== null) {
            return $this->repository->findByFarmId($farmId);
        }
        return $this->repository->findAll();
    }
}
