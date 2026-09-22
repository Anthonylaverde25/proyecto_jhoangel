<?php

declare(strict_types=1);

namespace App\Application\UseCases\Batches;

use App\Core\Entities\BatchEntity;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IBatchRepository;

/**
 * Changes the management system of a batch (confined pen vs. extensive pasture).
 *
 * Unlike the (activity, type) pair, which is immutable, this flag is expected to
 * change over the batch lifetime: the typical case is a troop that winters in a pen
 * and goes back to pasture in spring. Switching feeding regime is NOT a livestock
 * movement, so this use case deliberately writes no weight milestones and creates
 * no caravan movements: the batch, its animals and its type are left untouched.
 */
final class ChangeBatchManagementUseCase
{
    public function __construct(
        private readonly IBatchRepository $repository
    ) {
    }

    public function __invoke(int $batchId, bool $isConfined): BatchEntity
    {
        $batch = $this->repository->findById($batchId);

        if (!$batch) {
            throw new DomainException('Lote no encontrado.');
        }

        $batch->setIsConfined($isConfined);

        return $this->repository->save($batch);
    }
}
