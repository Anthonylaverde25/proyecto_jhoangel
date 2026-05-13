<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\RecordCaravanWeightDTO;
use App\Core\Entities\CaravanWeightEntity;
use App\Core\Interfaces\ICaravanWeightRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Services\BatchWeightService;
use Illuminate\Support\Facades\DB;

final class RecordCaravanWeightUseCase
{
    public function __construct(
        private readonly ICaravanWeightRepository $caravanWeightRepository,
        private readonly ICaravanRepository $caravanRepository,
        private readonly BatchWeightService $batchWeightService
    ) {
    }

    public function __invoke(RecordCaravanWeightDTO $dto): void
    {
        DB::transaction(function () use ($dto) {
            // 1. Mark all existing weights as non-current
            $this->caravanWeightRepository->markAllNonCurrentForCaravan($dto->caravanId);

            // 2. Create new weight as current
            $entity = new CaravanWeightEntity(
                null,
                $dto->caravanId,
                $dto->weight,
                true,
                new \DateTime($dto->weighingDate),
                $dto->notes
            );

            $this->caravanWeightRepository->save($entity);

            // 3. Recalculate batch weight if the caravan is assigned to a batch
            $caravan = $this->caravanRepository->findById($dto->caravanId);
            if ($caravan && $caravan->getBatchId()) {
                $this->batchWeightService->recalculateBatchWeight($caravan->getBatchId());
            }
        });
    }
}
