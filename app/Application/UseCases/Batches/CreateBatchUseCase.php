<?php

declare(strict_types=1);

namespace App\Application\UseCases\Batches;

use App\Application\DTOs\CreateBatchDTO;
use App\Core\Entities\BatchEntity;
use App\Core\Interfaces\IBatchRepository;

final class CreateBatchUseCase
{
    public function __construct(
        private readonly IBatchRepository $repository
    ) {
    }

    public function __invoke(CreateBatchDTO $dto): BatchEntity
    {
        $entity = new BatchEntity(
            id: null,
            name: $dto->name,
            farmId: $dto->farmId,
            observaciones: $dto->observaciones,
            isActive: true
        );

        return $this->repository->save($entity);
    }
}
