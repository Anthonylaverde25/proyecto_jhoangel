<?php

declare(strict_types=1);

namespace App\Application\UseCases\Batches;

use App\Core\Interfaces\IBatchRepository;
use App\Core\Entities\BatchEntity;
use App\Core\Exceptions\DomainException;

final class ChangeBatchActivityUseCase
{
    public function __construct(
        private readonly IBatchRepository $repository
    ) {
    }

    public function __invoke(int $batchId, int $activityId, ?float $weight = null): BatchEntity
    {
        $batch = $this->repository->findById($batchId);

        if (!$batch) {
            throw new DomainException("Lote no encontrado.");
        }

        $batch->setActivityId($activityId);
        $savedBatch = $this->repository->save($batch);

        if ($weight !== null) {
            $this->repository->addWeight($batchId, $weight, 'TRANSFER', new \DateTimeImmutable(), $activityId);
        }

        return $savedBatch;
    }
}
