<?php

declare(strict_types=1);

namespace App\Application\UseCases\Batches;

use App\Core\Interfaces\IBatchRepository;
use App\Core\Exceptions\DomainException;

final class GetBatchWeightHistoryUseCase
{
    public function __construct(
        private readonly IBatchRepository $repository
    ) {
    }

    public function __invoke(int $batchId): array
    {
        $batch = $this->repository->findById($batchId);

        if (!$batch) {
            throw new DomainException("Lote no encontrado.");
        }

        return $this->repository->getWeights($batchId);
    }
}
