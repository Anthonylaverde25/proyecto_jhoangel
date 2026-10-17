<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\BulkRecordCaravanWeightDTO;
use App\Core\Enums\BatchWeightCause;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Services\BatchWeightService;
use App\Core\Services\CaravanWeighingRecorder;
use Illuminate\Support\Facades\DB;

final class BulkRecordCaravanWeightUseCase
{
    public function __construct(
        private readonly CaravanWeighingRecorder $recorder,
        private readonly ICaravanRepository $caravanRepository,
        private readonly BatchWeightService $batchWeightService
    ) {
    }

    public function __invoke(BulkRecordCaravanWeightDTO $dto): void
    {
        DB::transaction(function () use ($dto) {
            /** @var array<int, array{date: \DateTimeInterface, type: \App\Core\Enums\WeighingTypeCode}> $batches */
            $batches = [];

            foreach ($dto->weights as $weightDto) {
                $reading = $weightDto->toReading();
                $this->recorder->record($weightDto->caravanId, $reading);

                $caravan = $this->caravanRepository->findById($weightDto->caravanId);
                $batchId = $caravan?->getBatchId();
                if ($batchId === null) {
                    continue;
                }

                // The point of the batch is dated on the day the animals were weighed, not on
                // the day the sheet was loaded.
                if (!isset($batches[$batchId]) || $reading->date > $batches[$batchId]['date']) {
                    $batches[$batchId] = ['date' => $reading->date, 'type' => $reading->type];
                }
            }

            foreach ($batches as $batchId => $point) {
                $this->batchWeightService->recalculateBatchWeight(
                    $batchId,
                    BatchWeightCause::CONTROL,
                    $point['date'],
                    $point['type']
                );
            }
        });
    }
}
