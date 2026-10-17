<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\RecordCaravanWeightDTO;
use App\Core\Enums\BatchWeightCause;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Services\BatchWeightService;
use App\Core\Services\CaravanWeighingRecorder;
use Illuminate\Support\Facades\DB;

final class RecordCaravanWeightUseCase
{
    public function __construct(
        private readonly CaravanWeighingRecorder $recorder,
        private readonly ICaravanRepository $caravanRepository,
        private readonly BatchWeightService $batchWeightService
    ) {
    }

    public function __invoke(RecordCaravanWeightDTO $dto): void
    {
        DB::transaction(function () use ($dto) {
            $reading = $dto->toReading();
            $this->recorder->record($dto->caravanId, $reading);

            $caravan = $this->caravanRepository->findById($dto->caravanId);
            if ($caravan && $caravan->getBatchId()) {
                $this->batchWeightService->recalculateBatchWeight(
                    $caravan->getBatchId(),
                    BatchWeightCause::CONTROL,
                    $reading->date,
                    $reading->type
                );
            }
        });
    }
}
