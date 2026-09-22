<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\BulkTransferCaravansDTO;
use App\Application\DTOs\CreateBatchDTO;
use App\Application\UseCases\Batches\CreateBatchUseCase;
use App\Application\UseCases\Batches\GetOrCreateReserveBatchUseCase;
use App\Core\Entities\CaravanMovementEntity;
use App\Core\Enums\BatchWeightCause;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanMovementRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IFarmRepository;
use App\Core\Interfaces\ICompanyRepository;
use App\Core\Services\BatchWeightService;
use Illuminate\Support\Facades\DB;

final class BulkTransferCaravansUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly IBatchRepository $batchRepository,
        private readonly ICaravanMovementRepository $movementRepository,
        private readonly IFarmRepository $farmRepository,
        private readonly ICompanyRepository $companyRepository,
        private readonly BatchWeightService $batchWeightService,
        private readonly GetOrCreateReserveBatchUseCase $getOrCreateReserveBatch,
        private readonly CreateBatchUseCase $createBatch
    ) {
    }

    /**
     * @return array{transferred_count: int, target_batch_id: int, target_batch_name: string}
     */
    public function __invoke(BulkTransferCaravansDTO $dto): array
    {
        return DB::transaction(function () use ($dto) {
            // 1. Resolve target batch. Three mutually exclusive paths: an existing batch,
            //    a brand new batch created inside this very transaction (so a failure later
            //    on never leaves an orphan batch behind), or the system reserve batch.
            if ($dto->newBatch !== null) {
                $targetBatch = ($this->createBatch)(CreateBatchDTO::fromArray($dto->newBatch));
            } elseif ($dto->targetBatchId !== null) {
                $targetBatch = $this->batchRepository->findById($dto->targetBatchId);
                if ($targetBatch === null) {
                    throw new DomainException("El lote de destino especificado no existe.");
                }
            } else {
                $targetBatch = ($this->getOrCreateReserveBatch)();
            }

            $targetBatchId = $targetBatch->getId();
            $targetBatchName = $targetBatch->getName();

            // 2. Resolve RENSPA from target batch farm or company
            $renspa = '';
            $farmId = $targetBatch->getFarmId();
            if ($farmId !== null) {
                $farm = $this->farmRepository->findById($farmId);
                if ($farm !== null) {
                    $renspa = $farm->getRenspa() ?? '';
                }
            }

            $movementDateStr = $dto->movementDate ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $movementDate = new \DateTime($movementDateStr);

            // First pass over the caravans, BEFORE anything moves: the source batches and
            // the composition they still hold have to be read now, because once the
            // animals change batch the previous membership is gone.
            $caravans = [];
            $sourceBatchIds = [];

            foreach ($dto->caravanIds as $caravanId) {
                $caravan = $this->caravanRepository->findById($caravanId);
                if ($caravan === null) {
                    continue;
                }

                $caravans[$caravanId] = $caravan;

                $previousBatchId = $caravan->getBatchId();
                if ($previousBatchId !== null && $previousBatchId !== $targetBatchId) {
                    $sourceBatchIds[$previousBatchId] = true;
                }
            }

            // Closing point of every composition about to change, on BOTH sides. The
            // destination needs it just as much as the source: without it, the stretch
            // from its last weighing to the arrival would blend the growth of the animals
            // already there with the entry of the new ones.
            foreach (array_keys($sourceBatchIds) as $srcBatchId) {
                $this->batchWeightService->snapshotBeforeMovement((int) $srcBatchId, $movementDate);
            }
            $this->batchWeightService->snapshotBeforeMovement($targetBatchId, $movementDate);

            $transferredCount = 0;

            foreach ($caravans as $caravanId => $caravan) {
                $previousBatchId = $caravan->getBatchId();

                // If RENSPA was not found from farm, fallback to company RENSPA
                $effectiveRenspa = $renspa;
                if (empty($effectiveRenspa) && $caravan->getCompanyId() !== null) {
                    $company = $this->companyRepository->findById($caravan->getCompanyId());
                    $effectiveRenspa = $company?->getRenspa() ?? '';
                }

                // Update caravan's batch
                $this->caravanRepository->updateBatchAndCategory($caravanId, $targetBatchId, null);

                // Record CaravanMovement
                $movementObservation = $dto->reason ?? ("Transferido al lote: " . $targetBatchName);
                $movement = new CaravanMovementEntity(
                    id: null,
                    caravanId: $caravanId,
                    companyId: $caravan->getCompanyId(),
                    renspa: $effectiveRenspa,
                    type: 'TRANSFER',
                    movementDate: $movementDate,
                    observations: $movementObservation,
                    fromBatchId: $previousBatchId,
                    toBatchId: $targetBatchId
                );
                $this->movementRepository->save($movement);

                $transferredCount++;
            }

            // 3. Opening point of the new composition on both sides. The vertical distance
            // to the closing point written above is, by construction, attributable to the
            // movement and not to elapsed time.
            foreach (array_keys($sourceBatchIds) as $srcBatchId) {
                $this->batchWeightService->recalculateBatchWeight((int) $srcBatchId, BatchWeightCause::MOVEMENT_OUT, $movementDate);
            }
            $this->batchWeightService->recalculateBatchWeight($targetBatchId, BatchWeightCause::MOVEMENT_IN, $movementDate);

            return [
                'transferred_count' => $transferredCount,
                'target_batch_id' => $targetBatchId,
                'target_batch_name' => $targetBatchName,
            ];
        });
    }
}
