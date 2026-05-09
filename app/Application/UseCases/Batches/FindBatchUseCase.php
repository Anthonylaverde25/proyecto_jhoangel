<?php

declare(strict_types=1);

namespace App\Application\UseCases\Batches;

use App\Core\Entities\BatchEntity;
use App\Core\Interfaces\IBatchRepository;

final class FindBatchUseCase
{
    public function __construct(
        private readonly IBatchRepository $repository
    ) {
    }

    public function __invoke(int $id): ?BatchEntity
    {
        return $this->repository->findById($id);
    }
}
