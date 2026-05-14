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
            isActive: true,
            activityId: $dto->activityId
        );

        $savedEntity = $this->repository->save($entity);

        // Siempre se genera el registro de peso inicial al crear el lote.
        // Si no se provee peso, se asume 0.0 para el historial.
        $this->repository->addWeight(
            $savedEntity->getId(),
            $dto->weight ?? 0.0,
            'INITIAL',
            new \DateTimeImmutable(),
            $dto->activityId
        );

        return $savedEntity;
    }
}
