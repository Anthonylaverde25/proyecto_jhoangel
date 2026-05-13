<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\BulkRecordCaravanWeightDTO;
use App\Core\Entities\CaravanWeightEntity;
use App\Core\Interfaces\ICaravanWeightRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Services\BatchWeightService;
use Illuminate\Support\Facades\DB;

final class BulkRecordCaravanWeightUseCase
{
    public function __construct(
        private readonly ICaravanWeightRepository $caravanWeightRepository,
        private readonly ICaravanRepository $caravanRepository,
        private readonly BatchWeightService $batchWeightService
    ) {
    }

    public function __invoke(BulkRecordCaravanWeightDTO $dto): void
    {
        DB::transaction(function () use ($dto) {
            $batchIdsToRecalculate = [];

            foreach ($dto->weights as $weightDto) {
                // 1. Mark existing as non-current
                $this->caravanWeightRepository->markAllNonCurrentForCaravan($weightDto->caravanId);

                // 2. Create new weight
                $entity = new CaravanWeightEntity(
                    null,
                    $weightDto->caravanId,
                    $weightDto->weight,
                    true,
                    new \DateTime($weightDto->weighingDate),
                    $weightDto->notes
                );

                $this->caravanWeightRepository->save($entity);

                // 3. Collect batch ID for recalculation
                $caravan = $this->caravanRepository->findById($weightDto->caravanId);
                if ($caravan && $caravan->getBatchId()) {
                    $batchIdsToRecalculate[] = $caravan->getBatchId();
                }
            }

            // 4. Recalculate unique batches once
            foreach (array_unique($batchIdsToRecalculate) as $batchId) {
                $this->batchWeightService->recalculateBatchWeight($batchId);
            }
        });
    }
}
