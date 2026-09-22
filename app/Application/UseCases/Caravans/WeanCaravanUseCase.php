<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\WeanCaravanDTO;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\ICaravanLineageRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\ICaravanWeightRepository;
use App\Core\Interfaces\ICaravanMovementRepository;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\IFarmRepository;
use App\Core\Interfaces\ICompanyRepository;
use App\Core\Entities\CaravanWeightEntity;
use App\Core\Entities\CaravanMovementEntity;
use App\Core\Services\BatchWeightService;
use Illuminate\Support\Facades\DB;
use App\Core\Enums\BatchWeightCause;

final class WeanCaravanUseCase
{
    public function __construct(
        private readonly ICaravanLineageRepository $lineageRepository,
        private readonly ICaravanRepository $caravanRepository,
        private readonly ICaravanWeightRepository $caravanWeightRepository,
        private readonly ICaravanMovementRepository $movementRepository,
        private readonly IBatchRepository $batchRepository,
        private readonly IFarmRepository $farmRepository,
        private readonly ICompanyRepository $companyRepository,
        private readonly BatchWeightService $batchWeightService,
        private readonly \App\Application\UseCases\Batches\CreateBatchUseCase $createBatchUseCase
    ) {
    }

    public function __invoke(WeanCaravanDTO $dto): void
    {
        DB::transaction(function () use ($dto) {
            $targetBatchId = $dto->targetBatchId;
            if ($dto->newBatch !== null) {
                $batchEntity = ($this->createBatchUseCase)($dto->newBatch);
                $targetBatchId = $batchEntity->getId();
            }

            if (!$targetBatchId) {
                throw new DomainException("No se especificó un lote de destino válido para el destete.");
            }

            // 1. Validate lineage
            $lineage = $this->lineageRepository->findByCaravanId($dto->caravanId);
            if ($lineage === null) {
                throw new DomainException("No se encontró registro de linaje para la caravana especificada.");
            }

            if (!$lineage->isNursing()) {
                throw new DomainException("La caravana ya se encuentra destetada.");
            }

            // 2. Validate offspring caravan
            $calf = $this->caravanRepository->findById($dto->caravanId);
            if ($calf === null) {
                throw new DomainException("No se encontró la caravana de la cría.");
            }

            // 3. Get RENSPA from target batch farm
            $batch = $this->batchRepository->findById($targetBatchId);
            if ($batch === null) {
                throw new DomainException("Lote de destino no encontrado.");
            }

            $renspa = '';
            $farmId = $batch->getFarmId();
            if ($farmId !== null) {
                $farm = $this->farmRepository->findById($farmId);
                if ($farm !== null) {
                    $renspa = $farm->getRenspa() ?? '';
                }
            } else {
                $companyId = $calf->getCompanyId();
                if ($companyId !== null) {
                    $company = $this->companyRepository->findById($companyId);
                    if ($company !== null) {
                        $renspa = $company->getRenspa() ?? '';
                    }
                }
            }

            // 4. Mark is_nursing = false in caravan_lineage
            $this->lineageRepository->wean($dto->caravanId);

            // 5. Update batch_id and category on caravan (origin batch read before it changes)
            $fromBatchId = $calf->getBatchId();
            $newCatId = $dto->newCategoryId;
            $newSubId = $dto->newSubcategoryId;
            if ($newCatId === null && $dto->newCategory !== null) {
                $searchCode = strtoupper($dto->newCategory);
                $codeMap = [
                    'TERNERA' => 'TERNERO',
                    'VACA_VACIA' => 'VACA',
                    'VACA VACIA' => 'VACA',
                ];
                $searchCode = $codeMap[$searchCode] ?? $searchCode;
                $newCatId = \App\Models\AnimalCategory::where('code', $searchCode)->value('id');
            } elseif ($newCatId === null && $calf->getCategoryId() === null) {
                $newCatId = \App\Models\AnimalCategory::where('code', 'TERNERO')->value('id');
            }
            $this->caravanRepository->updateBatchAndCategory($dto->caravanId, $targetBatchId, $newCatId, $newSubId);

            // 6. Record weaning weight (and mark previous current ones as non-current).
            // Without a weight the calf keeps its last recorded one.
            if ($dto->weaningWeight !== null) {
                $this->caravanWeightRepository->markAllNonCurrentForCaravan($dto->caravanId);
                $weightEntity = new CaravanWeightEntity(
                    id: null,
                    caravanId: $dto->caravanId,
                    weight: $dto->weaningWeight,
                    current: true,
                    weighingDate: new \DateTime($dto->weaningDate),
                    notes: $dto->notes ?? 'Weaning weight'
                );
                $this->caravanWeightRepository->save($weightEntity);
            }

            // 7. Record CaravanMovement of type WEANING
            $movementEntity = new CaravanMovementEntity(
                id: null,
                caravanId: $dto->caravanId,
                companyId: $calf->getCompanyId(),
                renspa: $renspa,
                type: 'WEANING',
                movementDate: new \DateTime($dto->weaningDate),
                observations: $dto->notes ?? "Weaned and moved to batch: " . $batch->getName(),
                fromBatchId: $fromBatchId,
                toBatchId: $targetBatchId
            );
            $this->movementRepository->save($movementEntity);

            // 8. Recalculate average weight of the target batch
            $this->batchWeightService->recalculateBatchWeight($targetBatchId, BatchWeightCause::MOVEMENT_IN);
        });
    }
}
