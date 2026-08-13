<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\BulkTransferCaravansDTO;
use App\Application\UseCases\Batches\GetOrCreateReserveBatchUseCase;
use App\Core\Entities\CaravanMovementEntity;
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
        private readonly GetOrCreateReserveBatchUseCase $getOrCreateReserveBatch
    ) {
    }

    /**
     * @return array{transferred_count: int, target_batch_id: int, target_batch_name: string}
     */
    public function __invoke(BulkTransferCaravansDTO $dto): array
    {
        return DB::transaction(function () use ($dto) {
            // 1. Resolve target batch (if not specified, auto-resolve reserve batch)
            if ($dto->targetBatchId !== null) {
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

            $sourceBatchIds = [];
            $transferredCount = 0;

            foreach ($dto->caravanIds as $caravanId) {
                $caravan = $this->caravanRepository->findById($caravanId);
                if ($caravan === null) {
                    continue;
                }

                $previousBatchId = $caravan->getBatchId();
                if ($previousBatchId !== null && $previousBatchId !== $targetBatchId) {
                    $sourceBatchIds[$previousBatchId] = true;
                }

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
                    observations: $movementObservation
                );
                $this->movementRepository->save($movement);

                $transferredCount++;
            }

            // 3. Recalculate weights for all affected source batches and the target batch
            foreach (array_keys($sourceBatchIds) as $srcBatchId) {
                $this->batchWeightService->recalculateBatchWeight((int) $srcBatchId);
            }
            $this->batchWeightService->recalculateBatchWeight($targetBatchId);

            return [
                'transferred_count' => $transferredCount,
                'target_batch_id' => $targetBatchId,
                'target_batch_name' => $targetBatchName,
            ];
        });
    }
}
