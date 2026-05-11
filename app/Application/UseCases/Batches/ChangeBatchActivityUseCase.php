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

        // Capturamos la actividad anterior antes de actualizar
        $oldActivityId = $batch->getActivityId();

        $batch->setActivityId($activityId);
        $savedBatch = $this->repository->save($batch);

        if ($weight !== null) {
            $now = new \DateTimeImmutable();

            // Registro de salida de la actividad anterior (si existía y es distinta)
            if ($oldActivityId !== null && $oldActivityId !== $activityId) {
                $this->repository->addWeight($batchId, $weight, 'TRANSFER', $now, $oldActivityId);
            }

            // Registro de entrada a la nueva actividad
            $this->repository->addWeight($batchId, $weight, 'INITIAL', $now, $activityId);
        }

        return $savedBatch;
    }
}
