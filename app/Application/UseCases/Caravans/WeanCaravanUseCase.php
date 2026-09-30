<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\WeanCaravanDTO;
use App\Application\UseCases\Batches\CreateBatchUseCase;
use App\Core\Entities\CaravanMovementEntity;
use App\Core\Entities\CaravanWeightEntity;
use App\Core\Enums\BatchWeightCause;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanLineageRepository;
use App\Core\Interfaces\ICaravanMovementRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\ICaravanWeightRepository;
use App\Core\Interfaces\ICompanyRepository;
use App\Core\Interfaces\IFarmRepository;
use App\Core\Services\BatchWeightService;
use Illuminate\Support\Facades\DB;

/**
 * Weans one calf: it stops nursing, moves to its weaning batch with a WEANING movement, may change
 * category and may record its weaning weight.
 *
 * It is the last step of every weaning, and every weaning reaches it through the DEST-01
 * processing — a scanned sheet, an order executed from the screen or a registered weaning.
 */
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
        private readonly CreateBatchUseCase $createBatchUseCase
    ) {
    }

    /**
     * @param bool $recalculateTargetWeight false when the caller weans a whole troop and
     *        recalculates each batch once at the end, instead of once per calf
     * @return int the id of the WEANING movement that records it
     *
     * @throws DomainException
     */
    public function __invoke(WeanCaravanDTO $dto, bool $recalculateTargetWeight = true): int
    {
        return DB::transaction(function () use ($dto, $recalculateTargetWeight): int {
            $targetBatchId = $dto->targetBatchId;
            if ($dto->newBatch !== null) {
                $batchEntity = ($this->createBatchUseCase)($dto->newBatch);
                $targetBatchId = $batchEntity->getId();
            }

            if (!$targetBatchId) {
                throw new DomainException('No se especificó un lote de destino válido para el destete.');
            }

            // 1. Validate lineage
            $lineage = $this->lineageRepository->findByCaravanId($dto->caravanId);
            if ($lineage === null) {
                throw new DomainException('No se encontró registro de linaje para la caravana especificada.');
            }

            if (!$lineage->isNursing()) {
                throw new DomainException('La caravana ya se encuentra destetada.');
            }

            // 2. Validate offspring caravan
            $calf = $this->caravanRepository->findById($dto->caravanId);
            if ($calf === null) {
                throw new DomainException('No se encontró la caravana de la cría.');
            }

            // 3. Get RENSPA from target batch farm
            $batch = $this->batchRepository->findById($targetBatchId);
            if ($batch === null) {
                throw new DomainException('Lote de destino no encontrado.');
            }

            $renspa = '';
            $farmId = $batch->getFarmId();
            if ($farmId !== null) {
                $renspa = $this->farmRepository->findById($farmId)?->getRenspa() ?? '';
            } elseif ($calf->getCompanyId() !== null) {
                $renspa = $this->companyRepository->findById($calf->getCompanyId())?->getRenspa() ?? '';
            }

            // 4. Mark is_nursing = false in caravan_lineage
            $this->lineageRepository->wean($dto->caravanId);

            // 5. Move the calf (origin batch read before it changes) and, when a new category was
            // decided, reclassify it whole: a new category without subcategory clears the old one.
            $fromBatchId = $calf->getBatchId();

            if ($dto->newCategoryId !== null) {
                $this->caravanRepository->updateBatchAndReclassify($dto->caravanId, $targetBatchId, $dto->newCategoryId, $dto->newSubcategoryId);
            } else {
                $newCatId = null;
                if ($dto->newCategory !== null) {
                    $searchCode = strtoupper($dto->newCategory);
                    $codeMap = [
                        'TERNERA' => 'TERNERO',
                        'VACA_VACIA' => 'VACA',
                        'VACA VACIA' => 'VACA',
                    ];
                    $searchCode = $codeMap[$searchCode] ?? $searchCode;
                    $newCatId = \App\Models\AnimalCategory::where('code', $searchCode)->value('id');
                } elseif ($calf->getCategoryId() === null) {
                    $newCatId = \App\Models\AnimalCategory::where('code', 'TERNERO')->value('id');
                }
                $this->caravanRepository->updateBatchAndCategory($dto->caravanId, $targetBatchId, $newCatId);
            }

            $weaningDate = new \DateTime($dto->weaningDate);

            // 6. Record the weaning weight. Without a weight the calf keeps its last recorded one;
            // a weaning loaded late joins the history without displacing a later current weight.
            if ($dto->weaningWeight !== null) {
                $isCurrent = !$this->caravanWeightRepository->hasWeighingAfter($dto->caravanId, $weaningDate);

                if ($isCurrent) {
                    $this->caravanWeightRepository->markAllNonCurrentForCaravan($dto->caravanId);
                }

                $this->caravanWeightRepository->save(new CaravanWeightEntity(
                    id: null,
                    caravanId: $dto->caravanId,
                    weight: $dto->weaningWeight,
                    current: $isCurrent,
                    weighingDate: $weaningDate,
                    notes: $dto->notes ?? 'Weaning weight'
                ));
            }

            // 7. Record CaravanMovement of type WEANING
            $movement = $this->movementRepository->save(new CaravanMovementEntity(
                id: null,
                caravanId: $dto->caravanId,
                companyId: $calf->getCompanyId(),
                renspa: $renspa,
                type: 'WEANING',
                movementDate: $weaningDate,
                observations: $dto->notes ?? 'Weaned and moved to batch: ' . $batch->getName(),
                fromBatchId: $fromBatchId,
                toBatchId: $targetBatchId
            ));

            // 8. Recalculate average weight of the target batch
            if ($recalculateTargetWeight) {
                $this->batchWeightService->recalculateBatchWeight($targetBatchId, BatchWeightCause::MOVEMENT_IN);
            }

            return (int) $movement->getId();
        });
    }
}
